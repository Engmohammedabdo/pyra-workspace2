<?php
/**
 * Supabase Storage Configuration
 * Copy this file to config.php and fill in your credentials
 */

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 86400);

define('SUPABASE_URL', 'https://your-supabase-instance.com');
define('SUPABASE_SERVICE_KEY', 'your-service-role-key-here');
define('SUPABASE_BUCKET', 'your-bucket-name');
define('MAX_UPLOAD_SIZE', 524288000); // 500MB
