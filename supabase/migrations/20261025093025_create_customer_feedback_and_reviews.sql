-- Migration: Create customer_feedback table, RPCs, and insert initial customer feedback & reviews data

-- 1. Create customer_feedback table if it doesn't exist
CREATE TABLE IF NOT EXISTS public.customer_feedback (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    submitted_at timestamp with time zone NOT NULL,
    respondent_type varchar(50) NOT NULL,
    portion_size varchar(50),
    favorite_flavor varchar(100),
    price_fairness varchar(100),
    website_issues text,
    suggested_flavor varchar(100),
    suggested_offer text,
    loved_most text,
    comments text,
    rating_taste smallint CHECK (rating_taste BETWEEN 1 AND 5),
    rating_portion smallint CHECK (rating_portion BETWEEN 1 AND 5),
    rating_price smallint CHECK (rating_price BETWEEN 1 AND 5),
    rating_overall smallint CHECK (rating_overall BETWEEN 1 AND 5),
    average_rating numeric(3, 2),
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);

-- Enable RLS and grants
ALTER TABLE public.customer_feedback OWNER TO postgres;
ALTER TABLE public.customer_feedback ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Allow read access to all" ON public.customer_feedback;
CREATE POLICY "Allow read access to all"
    ON public.customer_feedback
    FOR SELECT
    USING (true);

DROP POLICY IF EXISTS "Allow insert access to anon and authenticated" ON public.customer_feedback;
CREATE POLICY "Allow insert access to anon and authenticated"
    ON public.customer_feedback
    FOR INSERT
    WITH CHECK (true);

GRANT ALL ON TABLE public.customer_feedback TO anon, authenticated, service_role;

-- 2. Create RPC function to fetch customer feedback for admin reporting
CREATE OR REPLACE FUNCTION public.get_customer_feedback(
    p_limit integer DEFAULT 50,
    p_offset integer DEFAULT 0
)
RETURNS TABLE (
    id bigint,
    submitted_at timestamp with time zone,
    respondent_type text,
    portion_size text,
    favorite_flavor text,
    price_fairness text,
    website_issues text,
    suggested_flavor text,
    suggested_offer text,
    loved_most text,
    comments text,
    rating_taste integer,
    rating_portion integer,
    rating_price integer,
    rating_overall integer,
    average_rating numeric,
    created_at timestamp with time zone
)
LANGUAGE sql
STABLE
SECURITY DEFINER
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

GRANT ALL ON FUNCTION public.get_customer_feedback(integer, integer) TO anon, authenticated, service_role;

-- 3. Insert customer survey data into customer_feedback
INSERT INTO public.customer_feedback (
    submitted_at,
    respondent_type,
    portion_size,
    favorite_flavor,
    price_fairness,
    website_issues,
    suggested_flavor,
    suggested_offer,
    loved_most,
    comments,
    rating_taste,
    rating_portion,
    rating_price,
    rating_overall,
    average_rating
)
SELECT * FROM (VALUES
    ('2026-10-04 20:42:10+08'::timestamptz, 'Student', 'Just right', 'Tuna Mayo', 'Yes, very affordable', 'Yes', 'Chicken Teriyaki', 'Student or loyalty discounts', 'dsa', 'dsad', 5::smallint, 5::smallint, 5::smallint, 5::smallint, 5.00::numeric),
    ('2026-10-04 20:42:27+08'::timestamptz, 'Student', 'Just right', 'Spam & Egg', 'Fair', 'Yes', 'Chicken Teriyaki', 'Bundle or group orders', 'Ewan', 'Ala', 4::smallint, 5::smallint, 5::smallint, 5::smallint, 4.75::numeric),
    ('2026-10-08 10:23:40+08'::timestamptz, 'Student', 'Too big', 'Tuna Mayo', 'Very Expensive', 'Yes', 'Omelet', 'Student or loyalty discounts', 'Packaging', 'Paminta sa tuna', 5::smallint, 1::smallint, 1::smallint, 1::smallint, 2.00::numeric),
    ('2026-10-08 10:24:57+08'::timestamptz, 'Student', 'Just right', 'Spam & Egg', 'A bit expensive', 'No', 'Chicken Teriyaki', 'Student or loyalty discounts', 'The egg', 'Add egg', 3::smallint, 3::smallint, 4::smallint, 5::smallint, 3.75::numeric),
    ('2026-10-08 10:29:08+08'::timestamptz, 'Professor', 'Just right', 'Tuna Mayo', 'Fair', 'The form has input focus bugged out', 'Chicken Teriyaki', 'Combo deals (snack + drink)', 'The flavor', 'N/A', 5::smallint, 5::smallint, 5::smallint, 5::smallint, 5.00::numeric),
    ('2026-10-08 11:56:47+08'::timestamptz, 'Student', 'Too small', 'Spam & Egg', 'A bit expensive', 'No', 'Chicken Teriyaki', 'Combo deals (snack + drink)', 'The Services', 'The onigiri is good but it could be better, try making the price lower..', 2::smallint, 3::smallint, 3::smallint, 3::smallint, 2.75::numeric)
) AS v(submitted_at, respondent_type, portion_size, favorite_flavor, price_fairness, website_issues, suggested_flavor, suggested_offer, loved_most, comments, rating_taste, rating_portion, rating_price, rating_overall, average_rating)
WHERE NOT EXISTS (
    SELECT 1 FROM public.customer_feedback cf WHERE cf.submitted_at = v.submitted_at AND cf.respondent_type = v.respondent_type
);

-- 4. Also insert into public.reviews table so it displays in standard reviews views
INSERT INTO public.reviews (
    product_id,
    customer_name,
    rating,
    comment,
    created_at
)
SELECT
    p.id AS product_id,
    v.customer_name,
    v.rating,
    v.comment,
    v.created_at
FROM (VALUES
    ('Tuna Mayo', 'Student', 5::smallint, 'Loved: dsa. Suggestions: dsad. Portion: Just right. Price: Yes, very affordable.', '2026-10-04 20:42:10+08'::timestamptz),
    ('Spam & Egg', 'Student', 5::smallint, 'Loved: Ewan. Comments: Ala. Portion: Just right. Price: Fair.', '2026-10-04 20:42:27+08'::timestamptz),
    ('Tuna Mayo', 'Student', 2::smallint, 'Loved: Packaging. Comments: Paminta sa tuna. Portion: Too big. Price: Very Expensive.', '2026-10-08 10:23:40+08'::timestamptz),
    ('Spam & Egg', 'Student', 4::smallint, 'Loved: The egg. Comments: Add egg. Portion: Just right. Price: A bit expensive.', '2026-10-08 10:24:57+08'::timestamptz),
    ('Tuna Mayo', 'Professor', 5::smallint, 'Loved: The flavor. Comments: N/A. Notes: The form has input focus bugged out. Portion: Just right. Price: Fair.', '2026-10-08 10:29:08+08'::timestamptz),
    ('Spam & Egg', 'Student', 3::smallint, 'Loved: The Services. Comments: The onigiri is good but it could be better, try making the price lower.. Portion: Too small. Price: A bit expensive.', '2026-10-08 11:56:47+08'::timestamptz)
) AS v(product_name, customer_name, rating, comment, created_at)
LEFT JOIN public.products p ON lower(p.name) = lower(v.product_name)
WHERE NOT EXISTS (
    SELECT 1 FROM public.reviews r WHERE r.created_at = v.created_at AND r.customer_name = v.customer_name
);
