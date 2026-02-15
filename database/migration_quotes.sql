-- =============================================
-- Pyra Workspace — Quotation & Contract System
-- Migration: Create quotes tables + settings
-- =============================================

-- Table: pyra_quotes (main quote records)
CREATE TABLE IF NOT EXISTS pyra_quotes (
    id TEXT PRIMARY KEY,
    quote_number TEXT NOT NULL,
    client_id TEXT REFERENCES pyra_clients(id),
    project_name TEXT,
    status TEXT DEFAULT 'draft' CHECK (status IN ('draft','sent','viewed','signed','expired','cancelled')),
    estimate_date DATE DEFAULT CURRENT_DATE,
    expiry_date DATE,
    currency TEXT DEFAULT 'AED',
    subtotal NUMERIC(12,2) DEFAULT 0,
    tax_rate NUMERIC(5,2) DEFAULT 15,
    tax_amount NUMERIC(12,2) DEFAULT 0,
    total NUMERIC(12,2) DEFAULT 0,
    notes TEXT,
    terms_conditions JSONB DEFAULT '[]'::jsonb,
    bank_details JSONB DEFAULT '{}'::jsonb,
    company_name TEXT,
    company_logo TEXT,
    client_name TEXT,
    client_email TEXT,
    client_company TEXT,
    client_phone TEXT,
    client_address TEXT,
    signature_data TEXT,
    signed_by TEXT,
    signed_at TIMESTAMPTZ,
    signed_ip TEXT,
    sent_at TIMESTAMPTZ,
    viewed_at TIMESTAMPTZ,
    created_by TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Table: pyra_quote_items (line items for each quote)
CREATE TABLE IF NOT EXISTS pyra_quote_items (
    id TEXT PRIMARY KEY,
    quote_id TEXT REFERENCES pyra_quotes(id) ON DELETE CASCADE,
    sort_order INT DEFAULT 0,
    description TEXT NOT NULL,
    quantity NUMERIC(10,2) DEFAULT 1,
    rate NUMERIC(12,2) DEFAULT 0,
    amount NUMERIC(12,2) DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Index for fast lookups
CREATE INDEX IF NOT EXISTS idx_pyra_quotes_client ON pyra_quotes(client_id);
CREATE INDEX IF NOT EXISTS idx_pyra_quotes_status ON pyra_quotes(status);
CREATE INDEX IF NOT EXISTS idx_pyra_quote_items_quote ON pyra_quote_items(quote_id);

-- Enable RLS policies
ALTER TABLE pyra_quotes ENABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_quote_items ENABLE ROW LEVEL SECURITY;

-- RLS: Allow service role full access
CREATE POLICY "service_role_quotes" ON pyra_quotes FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "service_role_items" ON pyra_quote_items FOR ALL USING (true) WITH CHECK (true);

-- Settings rows (pyra_settings columns: key, value, updated_by, updated_at)
INSERT INTO pyra_settings (key, value)
SELECT 'quote_number_counter', '1'
WHERE NOT EXISTS (SELECT 1 FROM pyra_settings WHERE key = 'quote_number_counter');

INSERT INTO pyra_settings (key, value)
SELECT 'quote_number_prefix', 'QT-'
WHERE NOT EXISTS (SELECT 1 FROM pyra_settings WHERE key = 'quote_number_prefix');

INSERT INTO pyra_settings (key, value)
SELECT 'quote_default_expiry_days', '30'
WHERE NOT EXISTS (SELECT 1 FROM pyra_settings WHERE key = 'quote_default_expiry_days');

INSERT INTO pyra_settings (key, value)
SELECT 'quote_company_name', 'Pyramedia'
WHERE NOT EXISTS (SELECT 1 FROM pyra_settings WHERE key = 'quote_company_name');
