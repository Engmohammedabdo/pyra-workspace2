<?php
/**
 * Quote System Migration — Creates tables via Supabase pg-meta API
 * Visit: http://localhost/Pyra-workspace2/database/run_migration.php
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/html; charset=utf-8');

function pgMetaQuery(string $sql): array {
    $url = SUPABASE_URL . '/pg/query';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $sql]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'X-Connection-Encrypted: false',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'data' => json_decode($response, true),
        'raw' => $response,
        'error' => $error
    ];
}

function dbCheck(string $table): bool {
    $url = SUPABASE_URL . '/rest/v1/' . $table . '?limit=0';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'apikey: ' . SUPABASE_SERVICE_KEY,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode === 200;
}

echo "<!DOCTYPE html><html><head><title>Quote Migration</title><style>
body{font-family:monospace;padding:40px;background:#1a1a2e;color:#e0e0e0;max-width:900px;margin:0 auto}
h1{color:#F97316}
.ok{color:#22c55e;font-weight:bold}
.err{color:#ef4444;font-weight:bold}
.warn{color:#fbbf24}
pre{background:#111;padding:16px;border-radius:8px;overflow-x:auto;font-size:13px;border:1px solid #333}
.btn{display:inline-block;padding:12px 24px;background:#F97316;color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer;text-decoration:none;margin:8px 4px}
.btn:hover{opacity:0.9}
</style></head><body>";

echo "<h1>🔧 Pyra Quote System — Migration</h1>";

// Check current state
$quotesExist = dbCheck('pyra_quotes');
$itemsExist = dbCheck('pyra_quote_items');

echo "<h2>Current Status</h2>";
echo "<p>pyra_quotes table: " . ($quotesExist ? "<span class='ok'>✅ EXISTS</span>" : "<span class='err'>❌ NOT FOUND</span>") . "</p>";
echo "<p>pyra_quote_items table: " . ($itemsExist ? "<span class='ok'>✅ EXISTS</span>" : "<span class='err'>❌ NOT FOUND</span>") . "</p>";

if ($quotesExist && $itemsExist) {
    echo "<h2 class='ok'>✅ Migration already applied — tables exist!</h2>";
    echo "<p><a href='../index.php' class='btn'>← Back to Pyra Workspace</a></p>";
    echo "</body></html>";
    exit;
}

// If POST, try to create via pg-meta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    echo "<h2>Running Migration...</h2>";

    $sqls = [
        'Create pyra_quotes' => "CREATE TABLE IF NOT EXISTS pyra_quotes (
            id TEXT PRIMARY KEY,
            quote_number TEXT NOT NULL,
            client_id TEXT,
            project_name TEXT,
            status TEXT DEFAULT 'draft',
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
        );",

        'Create pyra_quote_items' => "CREATE TABLE IF NOT EXISTS pyra_quote_items (
            id TEXT PRIMARY KEY,
            quote_id TEXT REFERENCES pyra_quotes(id) ON DELETE CASCADE,
            sort_order INT DEFAULT 0,
            description TEXT NOT NULL,
            quantity NUMERIC(10,2) DEFAULT 1,
            rate NUMERIC(12,2) DEFAULT 0,
            amount NUMERIC(12,2) DEFAULT 0,
            created_at TIMESTAMPTZ DEFAULT NOW()
        );",

        'Create indexes' => "CREATE INDEX IF NOT EXISTS idx_pyra_quotes_client ON pyra_quotes(client_id);
            CREATE INDEX IF NOT EXISTS idx_pyra_quotes_status ON pyra_quotes(status);
            CREATE INDEX IF NOT EXISTS idx_pyra_quote_items_quote ON pyra_quote_items(quote_id);",

        'Enable RLS quotes' => "ALTER TABLE pyra_quotes ENABLE ROW LEVEL SECURITY;",
        'Enable RLS items' => "ALTER TABLE pyra_quote_items ENABLE ROW LEVEL SECURITY;",
        'RLS Policy quotes' => "CREATE POLICY \"service_role_quotes\" ON pyra_quotes FOR ALL USING (true) WITH CHECK (true);",
        'RLS Policy items' => "CREATE POLICY \"service_role_items\" ON pyra_quote_items FOR ALL USING (true) WITH CHECK (true);"
    ];

    foreach ($sqls as $label => $sql) {
        $result = pgMetaQuery($sql);
        if ($result['httpCode'] >= 200 && $result['httpCode'] < 300) {
            echo "<p><span class='ok'>✅</span> $label — HTTP {$result['httpCode']}</p>";
        } else {
            echo "<p><span class='err'>❌</span> $label — HTTP {$result['httpCode']}</p>";
            echo "<pre>" . htmlspecialchars($result['raw'] ?: $result['error']) . "</pre>";
        }
    }

    // Verify
    $quotesNow = dbCheck('pyra_quotes');
    $itemsNow = dbCheck('pyra_quote_items');

    echo "<h2>Verification</h2>";
    echo "<p>pyra_quotes: " . ($quotesNow ? "<span class='ok'>✅ OK</span>" : "<span class='err'>❌ FAILED</span>") . "</p>";
    echo "<p>pyra_quote_items: " . ($itemsNow ? "<span class='ok'>✅ OK</span>" : "<span class='err'>❌ FAILED</span>") . "</p>";

    if (!$quotesNow || !$itemsNow) {
        echo "<h2 class='warn'>⚠️ pg-meta API may not be available. Please run the SQL manually.</h2>";
        echo "<p>Copy the SQL below and run it in your Supabase SQL Editor:</p>";
        echo "<pre>" . htmlspecialchars(file_get_contents(__DIR__ . '/migration_quotes.sql')) . "</pre>";
    } else {
        echo "<h2 class='ok'>✅ Migration successful!</h2>";

        // Insert settings
        $settingsUrl = SUPABASE_URL . '/rest/v1/pyra_settings';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $settingsUrl . '?key=eq.quote_number_counter&limit=1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'apikey: ' . SUPABASE_SERVICE_KEY,
        ]);
        $sRes = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (empty($sRes)) {
            echo "<p>Inserting default settings...</p>";

            $settings = [
                ['key' => 'quote_number_counter', 'value' => '1'],
                ['key' => 'quote_number_prefix', 'value' => 'QT-'],
                ['key' => 'quote_default_expiry_days', 'value' => '30'],
                ['key' => 'quote_company_name', 'value' => 'Pyramedia'],
            ];

            foreach ($settings as $s) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $settingsUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($s));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
                    'apikey: ' . SUPABASE_SERVICE_KEY,
                    'Content-Type: application/json',
                    'Prefer: return=minimal',
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                echo "<p>Setting {$s['key']}: HTTP $code</p>";
            }
        }
    }

    echo "<p><a href='../index.php' class='btn'>← Back to Pyra Workspace</a></p>";
    echo "</body></html>";
    exit;
}

// Show form
echo "<h2>Tables need to be created</h2>";
echo "<form method='POST'><input type='hidden' name='run' value='1'>";
echo "<button type='submit' class='btn'>🚀 Run Migration (try pg-meta API)</button></form>";
echo "<br>";
echo "<h2>Or run this SQL manually in Supabase SQL Editor:</h2>";
echo "<pre>" . htmlspecialchars(file_get_contents(__DIR__ . '/migration_quotes.sql')) . "</pre>";
echo "</body></html>";
