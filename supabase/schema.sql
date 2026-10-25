


SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;


COMMENT ON SCHEMA "public" IS 'standard public schema';



CREATE EXTENSION IF NOT EXISTS "pg_stat_statements" WITH SCHEMA "extensions";






CREATE EXTENSION IF NOT EXISTS "pgcrypto" WITH SCHEMA "extensions";






CREATE EXTENSION IF NOT EXISTS "supabase_vault" WITH SCHEMA "vault";






CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA "extensions";






CREATE OR REPLACE FUNCTION "public"."adjust_inventory"("p_product_id" bigint, "p_adjustment" integer, "p_low_stock_limit" integer DEFAULT 5) RETURNS "jsonb"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
DECLARE
    v_product record;
    v_current_qty integer;
    v_new_qty integer;
    v_limit integer := GREATEST(0, COALESCE(p_low_stock_limit, 5));
BEGIN
    SELECT * INTO v_product
    FROM public.products
    WHERE id = p_product_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Product #% not found', p_product_id;
    END IF;

    SELECT quantity INTO v_current_qty
    FROM public.inventory
    WHERE product_id = p_product_id
    FOR UPDATE;

    IF NOT FOUND THEN
        v_current_qty := COALESCE(v_product.stock, 0);
    END IF;

    v_new_qty := v_current_qty + p_adjustment;
    IF v_new_qty < 0 THEN
        RAISE EXCEPTION 'Stock cannot be reduced below zero (Current: %, Adjustment: %)', v_current_qty, p_adjustment;
    END IF;

    INSERT INTO public.inventory (product_id, quantity, low_stock_limit, updated_at)
    VALUES (p_product_id, v_new_qty, v_limit, CURRENT_TIMESTAMP)
    ON CONFLICT (product_id)
    DO UPDATE SET
        quantity = v_new_qty,
        low_stock_limit = v_limit,
        updated_at = CURRENT_TIMESTAMP;

    UPDATE public.products
    SET stock = v_new_qty
    WHERE id = p_product_id;

    RETURN jsonb_build_object(
        'success', true,
        'product_id', p_product_id,
        'previous_quantity', v_current_qty,
        'new_quantity', v_new_qty,
        'low_stock_limit', v_limit
    );
END;
$$;


ALTER FUNCTION "public"."adjust_inventory"("p_product_id" bigint, "p_adjustment" integer, "p_low_stock_limit" integer) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."cancel_order"("p_order_id" bigint, "p_user_id" bigint DEFAULT NULL::bigint) RETURNS "jsonb"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
DECLARE
    v_order record;
    v_item record;
    v_restored_items jsonb := '[]'::jsonb;
BEGIN
    SELECT * INTO v_order
    FROM public.orders
    WHERE id = p_order_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Order #% not found', p_order_id;
    END IF;

    IF p_user_id IS NOT NULL AND v_order.user_id IS DISTINCT FROM p_user_id THEN
        RAISE EXCEPTION 'Unauthorized to cancel this order';
    END IF;

    IF lower(v_order.status) IN ('cancelled') THEN
        RAISE EXCEPTION 'Order is already cancelled';
    END IF;

    IF lower(v_order.status) IN ('completed', 'out for delivery', 'delivered') THEN
        RAISE EXCEPTION 'Completed orders cannot be cancelled';
    END IF;

    UPDATE public.orders
    SET status = 'Cancelled'
    WHERE id = p_order_id;

    FOR v_item IN
        SELECT oi.product_id, oi.quantity, oi.price, p.name, p.image
        FROM public.order_items oi
        LEFT JOIN public.products p ON p.id = oi.product_id
        WHERE oi.order_id = p_order_id
    LOOP
        IF v_item.product_id IS NOT NULL THEN
            UPDATE public.products
            SET stock = stock + v_item.quantity
            WHERE id = v_item.product_id;

            INSERT INTO public.inventory (product_id, quantity, low_stock_limit, updated_at)
            VALUES (v_item.product_id, v_item.quantity, 5, CURRENT_TIMESTAMP)
            ON CONFLICT (product_id)
            DO UPDATE SET
                quantity = public.inventory.quantity + v_item.quantity,
                updated_at = CURRENT_TIMESTAMP;

            v_restored_items := v_restored_items || jsonb_build_object(
                'id', v_item.product_id,
                'name', COALESCE(v_item.name, 'Item'),
                'price', v_item.price,
                'qty', v_item.quantity,
                'image', COALESCE(v_item.image, '')
            );
        END IF;
    END LOOP;

    RETURN jsonb_build_object(
        'success', true,
        'order_id', p_order_id,
        'status', 'Cancelled',
        'restored_items', v_restored_items
    );
END;
$$;


ALTER FUNCTION "public"."cancel_order"("p_order_id" bigint, "p_user_id" bigint) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."create_order"("p_user_id" bigint, "p_customer_name" "text", "p_phone" "text", "p_address" "text", "p_notes" "text", "p_payment_method" "text", "p_items" "jsonb") RETURNS "jsonb"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
DECLARE
    v_order_id bigint;
    v_total numeric(10,2) := 0.0;
    v_item jsonb;
    v_product_id bigint;
    v_qty integer;
    v_product_name text;
    v_product_price numeric(10,2);
    v_product_status text;
    v_current_stock integer;
    v_order_status text;
BEGIN
    IF p_customer_name IS NULL OR trim(p_customer_name) = '' THEN
        RAISE EXCEPTION 'Customer name is required';
    END IF;
    IF p_phone IS NULL OR trim(p_phone) = '' THEN
        RAISE EXCEPTION 'Phone number is required';
    END IF;
    IF p_address IS NULL OR trim(p_address) = '' THEN
        RAISE EXCEPTION 'Delivery address is required';
    END IF;
    IF p_items IS NULL OR jsonb_array_length(p_items) = 0 THEN
        RAISE EXCEPTION 'Cart cannot be empty';
    END IF;

    IF p_payment_method = 'Cash on Delivery' THEN
        v_order_status := 'Order Placed';
    ELSE
        v_order_status := 'Awaiting Payment';
    END IF;

    -- Validate stock and compute total with row locks
    FOR v_item IN SELECT * FROM jsonb_array_elements(p_items)
    LOOP
        v_product_id := (v_item->>'id')::bigint;
        v_qty := GREATEST(1, LEAST(99, COALESCE((v_item->>'qty')::integer, 1)));

        SELECT name, price, status, stock
        INTO v_product_name, v_product_price, v_product_status, v_current_stock
        FROM public.products
        WHERE id = v_product_id
        FOR UPDATE;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Product #% is no longer available', v_product_id;
        END IF;

        IF lower(COALESCE(v_product_status, 'active')) NOT IN ('active', 'available', 'in stock', 'instock') THEN
            RAISE EXCEPTION 'Product "%" is currently unavailable', v_product_name;
        END IF;

        -- Inventory stock takes precedence if present
        SELECT COALESCE(i.quantity, v_current_stock)
        INTO v_current_stock
        FROM public.products p
        LEFT JOIN public.inventory i ON i.product_id = p.id
        WHERE p.id = v_product_id;

        IF v_current_stock <= 0 THEN
            RAISE EXCEPTION 'Product "%" is out of stock', v_product_name;
        END IF;

        IF v_qty > v_current_stock THEN
            RAISE EXCEPTION 'Only % item(s) available for "%"', v_current_stock, v_product_name;
        END IF;

        v_total := v_total + (v_product_price * v_qty);
    END LOOP;

    -- Create order record
    INSERT INTO public.orders (
        user_id,
        customer_name,
        phone,
        address,
        notes,
        total,
        status,
        payment_method,
        payment_status,
        order_date,
        created_at
    )
    VALUES (
        p_user_id,
        p_customer_name,
        p_phone,
        p_address,
        NULLIF(trim(p_notes), ''),
        v_total,
        v_order_status,
        p_payment_method,
        'pending',
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )
    RETURNING id INTO v_order_id;

    -- Insert order items & atomically deduct stock
    FOR v_item IN SELECT * FROM jsonb_array_elements(p_items)
    LOOP
        v_product_id := (v_item->>'id')::bigint;
        v_qty := GREATEST(1, LEAST(99, COALESCE((v_item->>'qty')::integer, 1)));

        SELECT name, price INTO v_product_name, v_product_price
        FROM public.products WHERE id = v_product_id;

        INSERT INTO public.order_items (order_id, product_id, product_name, quantity, price)
        VALUES (v_order_id, v_product_id, v_product_name, v_qty, v_product_price);

        -- Deduct from products
        UPDATE public.products
        SET stock = GREATEST(0, stock - v_qty)
        WHERE id = v_product_id;

        -- Deduct from inventory
        INSERT INTO public.inventory (product_id, quantity, low_stock_limit, updated_at)
        VALUES (v_product_id, GREATEST(0, (SELECT stock FROM public.products WHERE id = v_product_id)), 5, CURRENT_TIMESTAMP)
        ON CONFLICT (product_id)
        DO UPDATE SET
            quantity = GREATEST(0, public.inventory.quantity - v_qty),
            updated_at = CURRENT_TIMESTAMP;
    END LOOP;

    RETURN jsonb_build_object(
        'success', true,
        'order_id', v_order_id,
        'status', v_order_status,
        'payment_method', p_payment_method,
        'total', v_total
    );
END;
$$;


ALTER FUNCTION "public"."create_order"("p_user_id" bigint, "p_customer_name" "text", "p_phone" "text", "p_address" "text", "p_notes" "text", "p_payment_method" "text", "p_items" "jsonb") OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_admin_customers"("p_search" "text" DEFAULT NULL::"text") RETURNS TABLE("id" bigint, "name" "text", "email" "text", "phone" "text", "address" "text", "role" "text", "created_at" timestamp with time zone, "order_count" bigint, "total_spent" numeric)
    LANGUAGE "sql" STABLE SECURITY DEFINER
    AS $$
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.address,
        u.role,
        u.created_at,
        COUNT(o.id)::bigint AS order_count,
        COALESCE(SUM(CASE WHEN lower(o.status) IN ('completed', 'delivered') THEN o.total ELSE 0 END), 0)::numeric AS total_spent
    FROM public.users u
    LEFT JOIN public.orders o ON o.user_id = u.id
    WHERE lower(COALESCE(u.role, 'customer')) = 'customer'
      AND (
          p_search IS NULL
          OR p_search = ''
          OR u.name ILIKE ('%' || p_search || '%')
          OR u.email ILIKE ('%' || p_search || '%')
          OR u.phone ILIKE ('%' || p_search || '%')
      )
    GROUP BY u.id, u.name, u.email, u.phone, u.address, u.role, u.created_at
    ORDER BY u.id DESC;
$$;


ALTER FUNCTION "public"."get_admin_customers"("p_search" "text") OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" "date" DEFAULT NULL::"date", "p_end_date" "date" DEFAULT NULL::"date") RETURNS "jsonb"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
DECLARE
    v_start date := COALESCE(p_start_date, (CURRENT_DATE - INTERVAL '29 days')::date);
    v_end date := COALESCE(p_end_date, CURRENT_DATE);
    v_total_sales numeric(10,2) := 0;
    v_total_orders integer := 0;
    v_pending_orders integer := 0;
    v_completed_orders integer := 0;
    v_low_stock_count integer := 0;
    v_product_count integer := 0;
    v_customer_count integer := 0;
BEGIN
    SELECT
        COUNT(*),
        COALESCE(SUM(CASE WHEN lower(status) IN ('completed', 'delivered') THEN total ELSE 0 END), 0),
        COUNT(*) FILTER (WHERE lower(status) IN ('order placed', 'pending', 'confirmed', 'preparing', 'ready', 'for verification')),
        COUNT(*) FILTER (WHERE lower(status) IN ('completed', 'delivered'))
    INTO v_total_orders, v_total_sales, v_pending_orders, v_completed_orders
    FROM public.orders
    WHERE (order_date::date BETWEEN v_start AND v_end);

    SELECT COUNT(*) INTO v_product_count
    FROM public.products
    WHERE lower(status) <> 'deleted';

    SELECT COUNT(*) INTO v_low_stock_count
    FROM public.inventory i
    JOIN public.products p ON p.id = i.product_id
    WHERE i.quantity <= i.low_stock_limit AND lower(p.status) <> 'deleted';

    SELECT COUNT(*) INTO v_customer_count
    FROM public.users
    WHERE lower(COALESCE(role, 'customer')) NOT IN ('admin', 'staff');

    RETURN jsonb_build_object(
        'sales', v_total_sales,
        'orders', v_total_orders,
        'pending', v_pending_orders,
        'completed', v_completed_orders,
        'low_stock', v_low_stock_count,
        'products', v_product_count,
        'customers', v_customer_count,
        'start_date', v_start,
        'end_date', v_end
    );
END;
$$;


ALTER FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" "date", "p_end_date" "date") OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" timestamp with time zone DEFAULT NULL::timestamp with time zone, "p_end_date" timestamp with time zone DEFAULT NULL::timestamp with time zone) RETURNS "jsonb"
    LANGUAGE "plpgsql" STABLE SECURITY DEFINER
    AS $$
DECLARE
    v_start timestamptz := COALESCE(p_start_date, (CURRENT_DATE - INTERVAL '30 days')::timestamptz);
    v_end timestamptz := COALESCE(p_end_date, CURRENT_TIMESTAMP);
    v_total_sales numeric(12,2) := 0.0;
    v_total_orders integer := 0;
    v_pending_orders integer := 0;
    v_completed_orders integer := 0;
    v_low_stock_count integer := 0;
    v_product_count integer := 0;
    v_customer_count integer := 0;
BEGIN
    SELECT
        COALESCE(SUM(CASE WHEN lower(status) IN ('completed', 'delivered') THEN total ELSE 0 END), 0.0),
        COUNT(*),
        COUNT(*) FILTER (WHERE lower(status) IN ('order placed', 'preparing', 'awaiting payment', 'for verification')),
        COUNT(*) FILTER (WHERE lower(status) IN ('completed', 'delivered'))
    INTO
        v_total_sales,
        v_total_orders,
        v_pending_orders,
        v_completed_orders
    FROM public.orders
    WHERE order_date BETWEEN v_start AND v_end;

    SELECT COUNT(*) INTO v_product_count
    FROM public.products
    WHERE lower(status) <> 'deleted';

    SELECT COUNT(*) INTO v_low_stock_count
    FROM public.inventory i
    JOIN public.products p ON p.id = i.product_id
    WHERE i.quantity <= i.low_stock_limit AND lower(p.status) <> 'deleted';

    SELECT COUNT(*) INTO v_customer_count
    FROM public.users
    WHERE lower(COALESCE(role, 'customer')) NOT IN ('admin', 'staff');

    RETURN jsonb_build_object(
        'sales', v_total_sales,
        'orders', v_total_orders,
        'pending', v_pending_orders,
        'completed', v_completed_orders,
        'low_stock', v_low_stock_count,
        'products', v_product_count,
        'customers', v_customer_count,
        'start_date', v_start,
        'end_date', v_end
    );
END;
$$;


ALTER FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" timestamp with time zone, "p_end_date" timestamp with time zone) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_admin_orders"("p_search" "text" DEFAULT NULL::"text", "p_status" "text" DEFAULT NULL::"text", "p_limit" integer DEFAULT 50, "p_offset" integer DEFAULT 0) RETURNS TABLE("id" bigint, "customer_name" "text", "display_name" "text", "email" "text", "phone" "text", "address" "text", "payment_method" "text", "payment_reference" "text", "paymongo_checkout_id" "text", "order_date" timestamp with time zone, "status" "text", "total" numeric, "notes" "text", "items" "jsonb")
    LANGUAGE "sql" STABLE SECURITY DEFINER
    AS $$
    SELECT
        o.id,
        o.customer_name,
        COALESCE(o.customer_name, u.name, 'Guest') AS display_name,
        u.email,
        COALESCE(o.phone, u.phone, '') AS phone,
        COALESCE(o.address, u.address, '') AS address,
        o.payment_method,
        o.payment_reference,
        o.paymongo_checkout_id,
        o.order_date,
        o.status,
        o.total,
        o.notes,
        COALESCE(
            (
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', oi.id,
                        'product_id', oi.product_id,
                        'product_name', oi.product_name,
                        'quantity', oi.quantity,
                        'price', oi.price
                    )
                )
                FROM public.order_items oi
                WHERE oi.order_id = o.id
            ),
            '[]'::jsonb
        ) AS items
    FROM public.orders o
    LEFT JOIN public.users u ON u.id = o.user_id
    WHERE (
        p_search IS NULL
        OR p_search = ''
        OR o.id::text ILIKE ('%' || p_search || '%')
        OR o.customer_name ILIKE ('%' || p_search || '%')
        OR u.name ILIKE ('%' || p_search || '%')
        OR o.payment_reference ILIKE ('%' || p_search || '%')
        OR o.paymongo_checkout_id ILIKE ('%' || p_search || '%')
    )
    AND (
        p_status IS NULL
        OR p_status = ''
        OR lower(p_status) = 'all'
        OR lower(o.status) = lower(p_status)
    )
    ORDER BY o.order_date DESC, o.id DESC
    LIMIT p_limit OFFSET p_offset;
$$;


ALTER FUNCTION "public"."get_admin_orders"("p_search" "text", "p_status" "text", "p_limit" integer, "p_offset" integer) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_admin_reviews"("p_limit" integer DEFAULT 50, "p_offset" integer DEFAULT 0) RETURNS TABLE("id" bigint, "customer_name" "text", "product_name" "text", "rating" integer, "comment" "text", "created_at" timestamp with time zone)
    LANGUAGE "sql" STABLE SECURITY DEFINER
    AS $$
    SELECT
        r.id,
        COALESCE(r.customer_name, u.name, 'Customer') AS customer_name,
        COALESCE(p.name, 'Product') AS product_name,
        r.rating,
        r.comment,
        r.created_at
    FROM public.reviews r
    LEFT JOIN public.users u ON u.id = r.user_id
    LEFT JOIN public.products p ON p.id = r.product_id
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT p_limit OFFSET p_offset;
$$;


ALTER FUNCTION "public"."get_admin_reviews"("p_limit" integer, "p_offset" integer) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_admin_staff"() RETURNS TABLE("id" bigint, "name" "text", "email" "text", "phone" "text", "role" "text", "created_at" timestamp with time zone)
    LANGUAGE "sql" STABLE SECURITY DEFINER
    AS $$
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.role,
        u.created_at
    FROM public.users u
    WHERE lower(u.role) IN ('admin', 'staff')
    ORDER BY u.role ASC, u.name ASC;
$$;


ALTER FUNCTION "public"."get_admin_staff"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_customer_feedback"("p_limit" integer DEFAULT 50, "p_offset" integer DEFAULT 0) RETURNS TABLE("id" bigint, "submitted_at" timestamp with time zone, "respondent_type" "text", "portion_size" "text", "favorite_flavor" "text", "price_fairness" "text", "website_issues" "text", "suggested_flavor" "text", "suggested_offer" "text", "loved_most" "text", "comments" "text", "rating_taste" integer, "rating_portion" integer, "rating_price" integer, "rating_overall" integer, "average_rating" numeric, "created_at" timestamp with time zone)
    LANGUAGE "sql" STABLE SECURITY DEFINER
    AS $$
    SELECT
        id,
        submitted_at,
        respondent_type::text,
        portion_size::text,
        favorite_flavor::text,
        price_fairness::text,
        website_issues::text,
        suggested_flavor::text,
        suggested_offer::text,
        loved_most::text,
        comments::text,
        rating_taste::integer,
        rating_portion::integer,
        rating_price::integer,
        rating_overall::integer,
        average_rating,
        created_at
    FROM public.customer_feedback
    ORDER BY submitted_at DESC, id DESC
    LIMIT p_limit OFFSET p_offset;
$$;


ALTER FUNCTION "public"."get_customer_feedback"("p_limit" integer, "p_offset" integer) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_payment_settings"() RETURNS TABLE("id" bigint, "gcash_name" "text", "gcash_number" "text", "gcash_qr" "text", "gcash_enabled" boolean, "cash_enabled" boolean, "updated_at" timestamp with time zone)
    LANGUAGE "sql" STABLE SECURITY DEFINER
    AS $$
    SELECT
        id,
        gcash_name,
        gcash_number,
        gcash_qr,
        gcash_enabled,
        cash_enabled,
        updated_at
    FROM public.payment_settings
    ORDER BY id ASC
    LIMIT 1;
$$;


ALTER FUNCTION "public"."get_payment_settings"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."get_products_with_inventory"() RETURNS TABLE("id" bigint, "name" character varying, "description" "text", "price" numeric, "image" character varying, "stock" integer, "status" character varying, "inventory_quantity" integer, "low_stock_limit" integer, "inventory_updated_at" timestamp with time zone)
    LANGUAGE "sql" STABLE SECURITY DEFINER
    AS $$
    SELECT
        p.id,
        p.name,
        p.description,
        p.price,
        p.image,
        p.stock,
        p.status,
        COALESCE(i.quantity, p.stock, 0)::integer AS inventory_quantity,
        COALESCE(i.low_stock_limit, 5)::integer AS low_stock_limit,
        i.updated_at AS inventory_updated_at
    FROM public.products p
    LEFT JOIN public.inventory i ON i.product_id = p.id
    WHERE lower(p.status) <> 'deleted'
    ORDER BY p.id ASC;
$$;


ALTER FUNCTION "public"."get_products_with_inventory"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."handle_new_user"() RETURNS "trigger"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
BEGIN
    INSERT INTO public.profiles (id, name, email)
    VALUES (
        NEW.id,
        COALESCE(NEW.raw_user_meta_data->>'name', split_part(NEW.email, '@', 1)),
        NEW.email
    );
    RETURN NEW;
END;
$$;


ALTER FUNCTION "public"."handle_new_user"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."is_admin"() RETURNS boolean
    LANGUAGE "plpgsql" STABLE SECURITY DEFINER
    AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM public.profiles
        WHERE id = auth.uid() AND role = 'admin'
    );
END;
$$;


ALTER FUNCTION "public"."is_admin"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."log_order_status_change"() RETURNS "trigger"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO public.order_status_history (order_id, status, changed_by)
        VALUES (NEW.id, NEW.status, auth.uid());
    ELSIF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status THEN
        INSERT INTO public.order_status_history (order_id, status, changed_by)
        VALUES (NEW.id, NEW.status, auth.uid());
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION "public"."log_order_status_change"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."prevent_role_self_promotion"() RETURNS "trigger"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
BEGIN
    IF NEW.role IS DISTINCT FROM OLD.role AND NOT public.is_admin() THEN
        RAISE EXCEPTION 'Only admins can change role';
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION "public"."prevent_role_self_promotion"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."rls_auto_enable"() RETURNS "event_trigger"
    LANGUAGE "plpgsql" SECURITY DEFINER
    SET "search_path" TO 'pg_catalog'
    AS $$
DECLARE
  cmd record;
BEGIN
  FOR cmd IN
    SELECT *
    FROM pg_event_trigger_ddl_commands()
    WHERE command_tag IN ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')
      AND object_type IN ('table','partitioned table')
  LOOP
     IF cmd.schema_name IS NOT NULL AND cmd.schema_name IN ('public') AND cmd.schema_name NOT IN ('pg_catalog','information_schema') AND cmd.schema_name NOT LIKE 'pg_toast%' AND cmd.schema_name NOT LIKE 'pg_temp%' THEN
      BEGIN
        EXECUTE format('alter table if exists %s enable row level security', cmd.object_identity);
        RAISE LOG 'rls_auto_enable: enabled RLS on %', cmd.object_identity;
      EXCEPTION
        WHEN OTHERS THEN
          RAISE LOG 'rls_auto_enable: failed to enable RLS on %', cmd.object_identity;
      END;
     ELSE
        RAISE LOG 'rls_auto_enable: skip % (either system schema or not in enforced list: %.)', cmd.object_identity, cmd.schema_name;
     END IF;
  END LOOP;
END;
$$;


ALTER FUNCTION "public"."rls_auto_enable"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."set_updated_at"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;


ALTER FUNCTION "public"."set_updated_at"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."track_order"("p_tracking_code" "uuid", "p_phone" character varying) RETURNS TABLE("id" integer, "tracking_code" "uuid", "status" character varying, "payment_status" character varying, "total" numeric, "order_date" timestamp with time zone, "history" "jsonb")
    LANGUAGE "plpgsql" STABLE SECURITY DEFINER
    AS $$
BEGIN
    RETURN QUERY
    SELECT
        o.id,
        o.tracking_code,
        o.status,
        o.payment_status,
        o.total,
        o.order_date,
        COALESCE(
            (SELECT jsonb_agg(jsonb_build_object('status', h.status, 'created_at', h.created_at) ORDER BY h.created_at)
             FROM public.order_status_history h WHERE h.order_id = o.id),
            '[]'::jsonb
        ) AS history
    FROM public.orders o
    WHERE o.tracking_code = p_tracking_code
    AND o.phone = p_phone;
END;
$$;


ALTER FUNCTION "public"."track_order"("p_tracking_code" "uuid", "p_phone" character varying) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."update_order_status"("p_order_id" bigint, "p_new_status" "text", "p_admin_id" bigint DEFAULT NULL::bigint) RETURNS "jsonb"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
DECLARE
    v_order record;
    v_previous_status text;
BEGIN
    SELECT * INTO v_order
    FROM public.orders
    WHERE id = p_order_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Order #% not found', p_order_id;
    END IF;

    v_previous_status := v_order.status;

    IF lower(v_previous_status) IN ('completed', 'cancelled') AND lower(v_previous_status) <> lower(p_new_status) THEN
        RAISE EXCEPTION 'Completed or cancelled orders cannot be reopened';
    END IF;

    UPDATE public.orders
    SET status = p_new_status,
        payment_status = CASE
            WHEN p_new_status = 'Completed' AND v_order.payment_method = 'Cash on Delivery' THEN 'paid'
            ELSE payment_status
        END
    WHERE id = p_order_id;

    RETURN jsonb_build_object(
        'success', true,
        'order_id', p_order_id,
        'previous_status', v_previous_status,
        'new_status', p_new_status
    );
END;
$$;


ALTER FUNCTION "public"."update_order_status"("p_order_id" bigint, "p_new_status" "text", "p_admin_id" bigint) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."update_payment_settings"("p_gcash_name" "text", "p_gcash_number" "text", "p_gcash_qr" "text" DEFAULT NULL::"text", "p_gcash_enabled" boolean DEFAULT true, "p_cash_enabled" boolean DEFAULT true) RETURNS "jsonb"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
BEGIN
    INSERT INTO public.payment_settings (
        id, gcash_name, gcash_number, gcash_qr, gcash_enabled, cash_enabled, updated_at
    )
    VALUES (
        1, p_gcash_name, p_gcash_number, COALESCE(p_gcash_qr, 'assets/images/gcash-qr.png'),
        p_gcash_enabled, p_cash_enabled, CURRENT_TIMESTAMP
    )
    ON CONFLICT (id) DO UPDATE SET
        gcash_name = EXCLUDED.gcash_name,
        gcash_number = EXCLUDED.gcash_number,
        gcash_qr = COALESCE(EXCLUDED.gcash_qr, public.payment_settings.gcash_qr),
        gcash_enabled = EXCLUDED.gcash_enabled,
        cash_enabled = EXCLUDED.cash_enabled,
        updated_at = CURRENT_TIMESTAMP;

    RETURN jsonb_build_object(
        'success', true,
        'gcash_name', p_gcash_name,
        'gcash_number', p_gcash_number,
        'gcash_enabled', p_gcash_enabled,
        'cash_enabled', p_cash_enabled
    );
END;
$$;


ALTER FUNCTION "public"."update_payment_settings"("p_gcash_name" "text", "p_gcash_number" "text", "p_gcash_qr" "text", "p_gcash_enabled" boolean, "p_cash_enabled" boolean) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."update_updated_at_column"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    AS $$
BEGIN
   NEW.updated_at = NOW();
   RETURN NEW;
END;
$$;


ALTER FUNCTION "public"."update_updated_at_column"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."upsert_product"("p_id" bigint, "p_name" "text", "p_description" "text", "p_price" numeric, "p_image" "text", "p_stock" integer, "p_status" "text" DEFAULT 'active'::"text", "p_low_stock_limit" integer DEFAULT 5) RETURNS "jsonb"
    LANGUAGE "plpgsql" SECURITY DEFINER
    AS $$
DECLARE
    v_product_id bigint := p_id;
    v_limit integer := GREATEST(0, COALESCE(p_low_stock_limit, 5));
    v_stock integer := GREATEST(0, COALESCE(p_stock, 0));
BEGIN
    IF p_name IS NULL OR trim(p_name) = '' THEN
        RAISE EXCEPTION 'Product name is required';
    END IF;
    IF p_price IS NULL OR p_price <= 0 THEN
        RAISE EXCEPTION 'Product price must be greater than zero';
    END IF;

    IF v_product_id IS NULL OR v_product_id <= 0 THEN
        INSERT INTO public.products (name, description, price, image, stock, status, created_at)
        VALUES (p_name, p_description, p_price, NULLIF(trim(p_image), ''), v_stock, COALESCE(p_status, 'active'), CURRENT_TIMESTAMP)
        RETURNING id INTO v_product_id;

        INSERT INTO public.inventory (product_id, quantity, low_stock_limit, updated_at)
        VALUES (v_product_id, v_stock, v_limit, CURRENT_TIMESTAMP)
        ON CONFLICT (product_id)
        DO UPDATE SET quantity = v_stock, low_stock_limit = v_limit, updated_at = CURRENT_TIMESTAMP;
    ELSE
        UPDATE public.products
        SET name = p_name,
            description = p_description,
            price = p_price,
            image = NULLIF(trim(p_image), ''),
            stock = v_stock,
            status = COALESCE(p_status, 'active')
        WHERE id = v_product_id;

        INSERT INTO public.inventory (product_id, quantity, low_stock_limit, updated_at)
        VALUES (v_product_id, v_stock, v_limit, CURRENT_TIMESTAMP)
        ON CONFLICT (product_id)
        DO UPDATE SET quantity = v_stock, low_stock_limit = v_limit, updated_at = CURRENT_TIMESTAMP;
    END IF;

    RETURN jsonb_build_object(
        'success', true,
        'product_id', v_product_id,
        'name', p_name,
        'price', p_price,
        'stock', v_stock,
        'low_stock_limit', v_limit
    );
END;
$$;


ALTER FUNCTION "public"."upsert_product"("p_id" bigint, "p_name" "text", "p_description" "text", "p_price" numeric, "p_image" "text", "p_stock" integer, "p_status" "text", "p_low_stock_limit" integer) OWNER TO "postgres";

SET default_tablespace = '';

SET default_table_access_method = "heap";


CREATE TABLE IF NOT EXISTS "public"."customer_feedback" (
    "id" bigint NOT NULL,
    "submitted_at" timestamp with time zone NOT NULL,
    "respondent_type" character varying(50) NOT NULL,
    "portion_size" character varying(50),
    "favorite_flavor" character varying(100),
    "price_fairness" character varying(100),
    "website_issues" "text",
    "suggested_flavor" character varying(100),
    "suggested_offer" "text",
    "loved_most" "text",
    "comments" "text",
    "rating_taste" smallint,
    "rating_portion" smallint,
    "rating_price" smallint,
    "rating_overall" smallint,
    "average_rating" numeric(3,2),
    "created_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "customer_feedback_rating_overall_check" CHECK ((("rating_overall" >= 1) AND ("rating_overall" <= 5))),
    CONSTRAINT "customer_feedback_rating_portion_check" CHECK ((("rating_portion" >= 1) AND ("rating_portion" <= 5))),
    CONSTRAINT "customer_feedback_rating_price_check" CHECK ((("rating_price" >= 1) AND ("rating_price" <= 5))),
    CONSTRAINT "customer_feedback_rating_taste_check" CHECK ((("rating_taste" >= 1) AND ("rating_taste" <= 5)))
);


ALTER TABLE "public"."customer_feedback" OWNER TO "postgres";


ALTER TABLE "public"."customer_feedback" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."customer_feedback_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



CREATE TABLE IF NOT EXISTS "public"."inventory" (
    "id" bigint NOT NULL,
    "product_id" bigint NOT NULL,
    "quantity" integer DEFAULT 0,
    "low_stock_limit" integer DEFAULT 5,
    "updated_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE ONLY "public"."inventory" REPLICA IDENTITY FULL;


ALTER TABLE "public"."inventory" OWNER TO "postgres";


ALTER TABLE "public"."inventory" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."inventory_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



CREATE TABLE IF NOT EXISTS "public"."order_items" (
    "id" bigint NOT NULL,
    "order_id" bigint NOT NULL,
    "product_id" bigint,
    "product_name" character varying(255) NOT NULL,
    "price" numeric(10,2) NOT NULL,
    "quantity" integer NOT NULL
);


ALTER TABLE "public"."order_items" OWNER TO "postgres";


ALTER TABLE "public"."order_items" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."order_items_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



CREATE TABLE IF NOT EXISTS "public"."orders" (
    "id" bigint NOT NULL,
    "user_id" bigint,
    "customer_name" character varying(120),
    "phone" character varying(40),
    "address" "text",
    "notes" "text",
    "total" numeric(10,2) NOT NULL,
    "status" character varying(40) DEFAULT 'Order Placed'::character varying NOT NULL,
    "payment_method" character varying(100) DEFAULT 'Cash on Delivery'::character varying,
    "payment_status" character varying(40) DEFAULT 'pending'::character varying NOT NULL,
    "paymongo_checkout_id" character varying(100) DEFAULT NULL::character varying,
    "order_date" timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    "created_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    "payment_reference" "text",
    "payment_proof" "text",
    "proof_uploaded_at" timestamp with time zone
);

ALTER TABLE ONLY "public"."orders" REPLICA IDENTITY FULL;


ALTER TABLE "public"."orders" OWNER TO "postgres";


ALTER TABLE "public"."orders" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."orders_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



CREATE TABLE IF NOT EXISTS "public"."password_resets" (
    "id" bigint NOT NULL,
    "user_id" bigint NOT NULL,
    "token_hash" character(64) NOT NULL,
    "expires_at" timestamp with time zone NOT NULL
);


ALTER TABLE "public"."password_resets" OWNER TO "postgres";


ALTER TABLE "public"."password_resets" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."password_resets_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



CREATE TABLE IF NOT EXISTS "public"."payment_settings" (
    "id" integer DEFAULT 1 NOT NULL,
    "gcash_name" character varying(150) DEFAULT 'ONISUBI Official'::character varying,
    "gcash_number" character varying(50) DEFAULT '09123456789'::character varying,
    "gcash_qr" "text" DEFAULT 'assets/images/gcash-qr.png'::"text",
    "gcash_enabled" boolean DEFAULT true,
    "cash_enabled" boolean DEFAULT true,
    "updated_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "single_payment_row" CHECK (("id" = 1))
);


ALTER TABLE "public"."payment_settings" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."products" (
    "id" bigint NOT NULL,
    "name" character varying(255) NOT NULL,
    "description" "text",
    "price" numeric(10,2) NOT NULL,
    "image" character varying(255) DEFAULT NULL::character varying,
    "stock" integer DEFAULT 0,
    "status" character varying(40) DEFAULT 'active'::character varying,
    "created_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE ONLY "public"."products" REPLICA IDENTITY FULL;


ALTER TABLE "public"."products" OWNER TO "postgres";


ALTER TABLE "public"."products" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."products_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



CREATE TABLE IF NOT EXISTS "public"."reviews" (
    "id" bigint NOT NULL,
    "user_id" bigint,
    "product_id" bigint,
    "order_id" bigint,
    "customer_name" character varying(120),
    "rating" smallint NOT NULL,
    "comment" "text",
    "created_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "reviews_rating_check" CHECK ((("rating" >= 1) AND ("rating" <= 5)))
);


ALTER TABLE "public"."reviews" OWNER TO "postgres";


ALTER TABLE "public"."reviews" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."reviews_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



CREATE TABLE IF NOT EXISTS "public"."users" (
    "id" bigint NOT NULL,
    "name" character varying(120) NOT NULL,
    "email" character varying(190) NOT NULL,
    "password_hash" character varying(255) NOT NULL,
    "phone" character varying(40) DEFAULT NULL::character varying,
    "address" "text",
    "role" character varying(50) DEFAULT 'customer'::character varying,
    "created_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE "public"."users" OWNER TO "postgres";


ALTER TABLE "public"."users" ALTER COLUMN "id" ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME "public"."users_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



ALTER TABLE ONLY "public"."customer_feedback"
    ADD CONSTRAINT "customer_feedback_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."inventory"
    ADD CONSTRAINT "inventory_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."inventory"
    ADD CONSTRAINT "inventory_product_id_key" UNIQUE ("product_id");



ALTER TABLE ONLY "public"."order_items"
    ADD CONSTRAINT "order_items_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."orders"
    ADD CONSTRAINT "orders_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."password_resets"
    ADD CONSTRAINT "password_resets_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."password_resets"
    ADD CONSTRAINT "password_resets_token_hash_key" UNIQUE ("token_hash");



ALTER TABLE ONLY "public"."payment_settings"
    ADD CONSTRAINT "payment_settings_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."products"
    ADD CONSTRAINT "products_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."reviews"
    ADD CONSTRAINT "reviews_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."users"
    ADD CONSTRAINT "users_email_key" UNIQUE ("email");



ALTER TABLE ONLY "public"."users"
    ADD CONSTRAINT "users_pkey" PRIMARY KEY ("id");



CREATE INDEX "idx_inventory_product_id" ON "public"."inventory" USING "btree" ("product_id");



CREATE INDEX "idx_orders_payment_reference" ON "public"."orders" USING "btree" ("payment_reference");



CREATE INDEX "idx_orders_paymongo_checkout_id" ON "public"."orders" USING "btree" ("paymongo_checkout_id");



CREATE INDEX "idx_orders_status" ON "public"."orders" USING "btree" ("status");



CREATE INDEX "idx_orders_user_id" ON "public"."orders" USING "btree" ("user_id");



CREATE OR REPLACE TRIGGER "update_inventory_updated_at" BEFORE UPDATE ON "public"."inventory" FOR EACH ROW EXECUTE FUNCTION "public"."update_updated_at_column"();



ALTER TABLE ONLY "public"."inventory"
    ADD CONSTRAINT "fk_inventory_product" FOREIGN KEY ("product_id") REFERENCES "public"."products"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."order_items"
    ADD CONSTRAINT "fk_order_items_order" FOREIGN KEY ("order_id") REFERENCES "public"."orders"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."order_items"
    ADD CONSTRAINT "fk_order_items_product" FOREIGN KEY ("product_id") REFERENCES "public"."products"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."orders"
    ADD CONSTRAINT "fk_orders_user" FOREIGN KEY ("user_id") REFERENCES "public"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."password_resets"
    ADD CONSTRAINT "fk_password_resets_user" FOREIGN KEY ("user_id") REFERENCES "public"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."reviews"
    ADD CONSTRAINT "reviews_order_id_fkey" FOREIGN KEY ("order_id") REFERENCES "public"."orders"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."reviews"
    ADD CONSTRAINT "reviews_product_id_fkey" FOREIGN KEY ("product_id") REFERENCES "public"."products"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."reviews"
    ADD CONSTRAINT "reviews_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "public"."users"("id") ON DELETE SET NULL;



CREATE POLICY "Allow insert access to anon and authenticated" ON "public"."customer_feedback" FOR INSERT WITH CHECK (true);



CREATE POLICY "Allow read access to all" ON "public"."customer_feedback" FOR SELECT USING (true);



ALTER TABLE "public"."customer_feedback" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."inventory" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "inventory_public_select" ON "public"."inventory" FOR SELECT USING (true);



ALTER TABLE "public"."order_items" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "order_items_public_insert" ON "public"."order_items" FOR INSERT WITH CHECK (true);



CREATE POLICY "order_items_public_select" ON "public"."order_items" FOR SELECT USING (true);



ALTER TABLE "public"."orders" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "orders_public_insert" ON "public"."orders" FOR INSERT WITH CHECK (true);



CREATE POLICY "orders_public_select" ON "public"."orders" FOR SELECT USING (true);



ALTER TABLE "public"."password_resets" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."payment_settings" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "payment_settings_public_select" ON "public"."payment_settings" FOR SELECT USING (true);



ALTER TABLE "public"."products" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "products_public_select" ON "public"."products" FOR SELECT USING (true);



ALTER TABLE "public"."reviews" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "reviews_public_select" ON "public"."reviews" FOR SELECT USING (true);



ALTER TABLE "public"."users" ENABLE ROW LEVEL SECURITY;




ALTER PUBLICATION "supabase_realtime" OWNER TO "postgres";






ALTER PUBLICATION "supabase_realtime" ADD TABLE ONLY "public"."inventory";



ALTER PUBLICATION "supabase_realtime" ADD TABLE ONLY "public"."orders";



ALTER PUBLICATION "supabase_realtime" ADD TABLE ONLY "public"."products";



GRANT USAGE ON SCHEMA "public" TO "postgres";
GRANT USAGE ON SCHEMA "public" TO "anon";
GRANT USAGE ON SCHEMA "public" TO "authenticated";
GRANT USAGE ON SCHEMA "public" TO "service_role";






















































































































































GRANT ALL ON FUNCTION "public"."adjust_inventory"("p_product_id" bigint, "p_adjustment" integer, "p_low_stock_limit" integer) TO "anon";
GRANT ALL ON FUNCTION "public"."adjust_inventory"("p_product_id" bigint, "p_adjustment" integer, "p_low_stock_limit" integer) TO "authenticated";
GRANT ALL ON FUNCTION "public"."adjust_inventory"("p_product_id" bigint, "p_adjustment" integer, "p_low_stock_limit" integer) TO "service_role";



GRANT ALL ON FUNCTION "public"."cancel_order"("p_order_id" bigint, "p_user_id" bigint) TO "anon";
GRANT ALL ON FUNCTION "public"."cancel_order"("p_order_id" bigint, "p_user_id" bigint) TO "authenticated";
GRANT ALL ON FUNCTION "public"."cancel_order"("p_order_id" bigint, "p_user_id" bigint) TO "service_role";



GRANT ALL ON FUNCTION "public"."create_order"("p_user_id" bigint, "p_customer_name" "text", "p_phone" "text", "p_address" "text", "p_notes" "text", "p_payment_method" "text", "p_items" "jsonb") TO "anon";
GRANT ALL ON FUNCTION "public"."create_order"("p_user_id" bigint, "p_customer_name" "text", "p_phone" "text", "p_address" "text", "p_notes" "text", "p_payment_method" "text", "p_items" "jsonb") TO "authenticated";
GRANT ALL ON FUNCTION "public"."create_order"("p_user_id" bigint, "p_customer_name" "text", "p_phone" "text", "p_address" "text", "p_notes" "text", "p_payment_method" "text", "p_items" "jsonb") TO "service_role";



GRANT ALL ON FUNCTION "public"."get_admin_customers"("p_search" "text") TO "anon";
GRANT ALL ON FUNCTION "public"."get_admin_customers"("p_search" "text") TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_admin_customers"("p_search" "text") TO "service_role";



GRANT ALL ON FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" "date", "p_end_date" "date") TO "anon";
GRANT ALL ON FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" "date", "p_end_date" "date") TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" "date", "p_end_date" "date") TO "service_role";



GRANT ALL ON FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" timestamp with time zone, "p_end_date" timestamp with time zone) TO "anon";
GRANT ALL ON FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" timestamp with time zone, "p_end_date" timestamp with time zone) TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_admin_dashboard_metrics"("p_start_date" timestamp with time zone, "p_end_date" timestamp with time zone) TO "service_role";



GRANT ALL ON FUNCTION "public"."get_admin_orders"("p_search" "text", "p_status" "text", "p_limit" integer, "p_offset" integer) TO "anon";
GRANT ALL ON FUNCTION "public"."get_admin_orders"("p_search" "text", "p_status" "text", "p_limit" integer, "p_offset" integer) TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_admin_orders"("p_search" "text", "p_status" "text", "p_limit" integer, "p_offset" integer) TO "service_role";



GRANT ALL ON FUNCTION "public"."get_admin_reviews"("p_limit" integer, "p_offset" integer) TO "anon";
GRANT ALL ON FUNCTION "public"."get_admin_reviews"("p_limit" integer, "p_offset" integer) TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_admin_reviews"("p_limit" integer, "p_offset" integer) TO "service_role";



GRANT ALL ON FUNCTION "public"."get_admin_staff"() TO "anon";
GRANT ALL ON FUNCTION "public"."get_admin_staff"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_admin_staff"() TO "service_role";



GRANT ALL ON FUNCTION "public"."get_customer_feedback"("p_limit" integer, "p_offset" integer) TO "anon";
GRANT ALL ON FUNCTION "public"."get_customer_feedback"("p_limit" integer, "p_offset" integer) TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_customer_feedback"("p_limit" integer, "p_offset" integer) TO "service_role";



GRANT ALL ON FUNCTION "public"."get_payment_settings"() TO "anon";
GRANT ALL ON FUNCTION "public"."get_payment_settings"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_payment_settings"() TO "service_role";



GRANT ALL ON FUNCTION "public"."get_products_with_inventory"() TO "anon";
GRANT ALL ON FUNCTION "public"."get_products_with_inventory"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."get_products_with_inventory"() TO "service_role";



GRANT ALL ON FUNCTION "public"."handle_new_user"() TO "anon";
GRANT ALL ON FUNCTION "public"."handle_new_user"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."handle_new_user"() TO "service_role";



GRANT ALL ON FUNCTION "public"."is_admin"() TO "anon";
GRANT ALL ON FUNCTION "public"."is_admin"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."is_admin"() TO "service_role";



GRANT ALL ON FUNCTION "public"."log_order_status_change"() TO "anon";
GRANT ALL ON FUNCTION "public"."log_order_status_change"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."log_order_status_change"() TO "service_role";



GRANT ALL ON FUNCTION "public"."prevent_role_self_promotion"() TO "anon";
GRANT ALL ON FUNCTION "public"."prevent_role_self_promotion"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."prevent_role_self_promotion"() TO "service_role";



GRANT ALL ON FUNCTION "public"."rls_auto_enable"() TO "anon";
GRANT ALL ON FUNCTION "public"."rls_auto_enable"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."rls_auto_enable"() TO "service_role";



GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "anon";
GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "service_role";



GRANT ALL ON FUNCTION "public"."track_order"("p_tracking_code" "uuid", "p_phone" character varying) TO "anon";
GRANT ALL ON FUNCTION "public"."track_order"("p_tracking_code" "uuid", "p_phone" character varying) TO "authenticated";
GRANT ALL ON FUNCTION "public"."track_order"("p_tracking_code" "uuid", "p_phone" character varying) TO "service_role";



GRANT ALL ON FUNCTION "public"."update_order_status"("p_order_id" bigint, "p_new_status" "text", "p_admin_id" bigint) TO "anon";
GRANT ALL ON FUNCTION "public"."update_order_status"("p_order_id" bigint, "p_new_status" "text", "p_admin_id" bigint) TO "authenticated";
GRANT ALL ON FUNCTION "public"."update_order_status"("p_order_id" bigint, "p_new_status" "text", "p_admin_id" bigint) TO "service_role";



GRANT ALL ON FUNCTION "public"."update_payment_settings"("p_gcash_name" "text", "p_gcash_number" "text", "p_gcash_qr" "text", "p_gcash_enabled" boolean, "p_cash_enabled" boolean) TO "anon";
GRANT ALL ON FUNCTION "public"."update_payment_settings"("p_gcash_name" "text", "p_gcash_number" "text", "p_gcash_qr" "text", "p_gcash_enabled" boolean, "p_cash_enabled" boolean) TO "authenticated";
GRANT ALL ON FUNCTION "public"."update_payment_settings"("p_gcash_name" "text", "p_gcash_number" "text", "p_gcash_qr" "text", "p_gcash_enabled" boolean, "p_cash_enabled" boolean) TO "service_role";



GRANT ALL ON FUNCTION "public"."update_updated_at_column"() TO "anon";
GRANT ALL ON FUNCTION "public"."update_updated_at_column"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."update_updated_at_column"() TO "service_role";



GRANT ALL ON FUNCTION "public"."upsert_product"("p_id" bigint, "p_name" "text", "p_description" "text", "p_price" numeric, "p_image" "text", "p_stock" integer, "p_status" "text", "p_low_stock_limit" integer) TO "anon";
GRANT ALL ON FUNCTION "public"."upsert_product"("p_id" bigint, "p_name" "text", "p_description" "text", "p_price" numeric, "p_image" "text", "p_stock" integer, "p_status" "text", "p_low_stock_limit" integer) TO "authenticated";
GRANT ALL ON FUNCTION "public"."upsert_product"("p_id" bigint, "p_name" "text", "p_description" "text", "p_price" numeric, "p_image" "text", "p_stock" integer, "p_status" "text", "p_low_stock_limit" integer) TO "service_role";


















GRANT ALL ON TABLE "public"."customer_feedback" TO "anon";
GRANT ALL ON TABLE "public"."customer_feedback" TO "authenticated";
GRANT ALL ON TABLE "public"."customer_feedback" TO "service_role";



GRANT ALL ON SEQUENCE "public"."customer_feedback_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."customer_feedback_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."customer_feedback_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."inventory" TO "anon";
GRANT ALL ON TABLE "public"."inventory" TO "authenticated";
GRANT ALL ON TABLE "public"."inventory" TO "service_role";



GRANT ALL ON SEQUENCE "public"."inventory_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."inventory_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."inventory_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."order_items" TO "anon";
GRANT ALL ON TABLE "public"."order_items" TO "authenticated";
GRANT ALL ON TABLE "public"."order_items" TO "service_role";



GRANT ALL ON SEQUENCE "public"."order_items_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."order_items_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."order_items_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."orders" TO "anon";
GRANT ALL ON TABLE "public"."orders" TO "authenticated";
GRANT ALL ON TABLE "public"."orders" TO "service_role";



GRANT ALL ON SEQUENCE "public"."orders_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."orders_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."orders_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."password_resets" TO "anon";
GRANT ALL ON TABLE "public"."password_resets" TO "authenticated";
GRANT ALL ON TABLE "public"."password_resets" TO "service_role";



GRANT ALL ON SEQUENCE "public"."password_resets_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."password_resets_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."password_resets_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."payment_settings" TO "anon";
GRANT ALL ON TABLE "public"."payment_settings" TO "authenticated";
GRANT ALL ON TABLE "public"."payment_settings" TO "service_role";



GRANT ALL ON TABLE "public"."products" TO "anon";
GRANT ALL ON TABLE "public"."products" TO "authenticated";
GRANT ALL ON TABLE "public"."products" TO "service_role";



GRANT ALL ON SEQUENCE "public"."products_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."products_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."products_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."reviews" TO "anon";
GRANT ALL ON TABLE "public"."reviews" TO "authenticated";
GRANT ALL ON TABLE "public"."reviews" TO "service_role";



GRANT ALL ON SEQUENCE "public"."reviews_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."reviews_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."reviews_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."users" TO "anon";
GRANT ALL ON TABLE "public"."users" TO "authenticated";
GRANT ALL ON TABLE "public"."users" TO "service_role";



GRANT ALL ON SEQUENCE "public"."users_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."users_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."users_id_seq" TO "service_role";









ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "service_role";



































