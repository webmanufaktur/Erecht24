<?php namespace ProcessWire;

/**
 * eRecht24 Legal Text Module for ProcessWire
 * 
 * Integrates with eRecht24 API to synchronize legal texts (imprint, privacy policy)
 * and automatically creates pages with legal content.
 * 
 * @version 0.2.1
 * @author ProcessWire Module
 * 
 */

class Erecht24 extends WireData implements Module, ConfigurableModule {

    const API_HOST = 'https://api.e-recht24.de';
    
    const LEGAL_TEXT_TYPES = [
        'imprint' => 'Impressum',
        'privacyPolicy' => 'Datenschutzerklärung', 
        'privacyPolicySocialMedia' => 'Datenschutzerklärung Social Media'
    ];

    const ALLOWED_PUSH_TYPES = [
        'ping',
        'imprint', 
        'privacyPolicy',
        'privacyPolicySocialMedia',
        'all'
    ];

    const DEFAULT_HTTP_TIMEOUT = 30;
    const DEFAULT_HTTP_RETRIES = 3;

    /**
     * Check if client is properly registered with eRecht24
     *
     * @return bool True if client has valid registration
     */
    protected function isClientRegistered() {
        $clientId = $this->getModuleSetting('client_id');
        return !empty($clientId) && $clientId !== 'Not registered yet';
    }

    /**
     * Get a module setting with config.php override support
     */
    protected function getModuleSetting($key) {
        $config = $this->wire('config');
        
        // Check for config.php override first
        $configKey = 'erecht24_' . $key;
        if(isset($config->$configKey)) {
            return $config->$configKey;
        }
        
        // Fall back to module configuration
        $moduleConfig = $this->wire('modules')->getConfig($this);
        return isset($moduleConfig[$key]) ? $moduleConfig[$key] : null;
    }

    /**
     * Set a module setting in ProcessWire module config
     */
    protected function setModuleSetting($key, $value) {
        try {
            $moduleConfig = $this->wire('modules')->getConfig($this);
            $moduleConfig[$key] = $value;
            $this->wire('modules')->saveConfig($this, $moduleConfig);
            return true;
        } catch(\Exception $e) {
            $this->log("Error setting module setting '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all module settings as array
     */
    protected function getAllModuleSettings() {
        return $this->wire('modules')->getConfig($this);
    }

    /**
     * Delete a module setting
     */
    protected function deleteModuleSetting($key) {
        try {
            $moduleConfig = $this->wire('modules')->getConfig($this);
            unset($moduleConfig[$key]);
            $this->wire('modules')->saveConfig($this, $moduleConfig);
            return true;
        } catch(\Exception $e) {
            $this->log("Error deleting module setting '$key': " . $e->getMessage());
            return false;
        }
    }


    /**
     * Handle webhook requests via URL hook
     */
    public function handleWebhook(HookEvent $event) {
        $input = $this->wire('input');
        $method = $this->getRequestMethod();
        $type = (string) $input->get('erecht24_type');
        
        if(!$type) {
            $this->sendJson(400, ['error' => 'Missing erecht24_type parameter']);
            return;
        }
        
        // Enforce methods: only GET for ping, POST for all others
        if(($type === 'ping' && $method !== 'GET') || ($type !== 'ping' && $method !== 'POST')) {
            $this->sendJson(405, ['error' => 'Method Not Allowed']);
            return;
        }
        
        try {
            $this->processWebhook();
        } catch(\Exception $e) {
            $this->log("eRecht24 webhook error: " . $e->getMessage());
            $this->sendJson(500, ['error' => 'Internal server error']);
        }
    }

    /**
     * Process the webhook request
     */
    protected function processWebhook() {
        $input = $this->wire('input');
        
        $this->log("eRecht24 webhook received: " . ($input->get('erecht24_type') ?: 'no type'));
        
        // Validate request
        if(!$this->validateWebhookRequest()) {
            $this->log("eRecht24 webhook validation failed");
            $this->sendJson(401, ['error' => 'Unauthorized']);
            return;
        }

        $type = $input->get('erecht24_type');
        $this->log("eRecht24 processing webhook type: $type");
        
        try {
            switch($type) {
                case 'ping':
                    $this->handlePing();
                    break;
                case 'imprint':
                    $this->handleLegalTextUpdate('imprint');
                    break;
                case 'privacyPolicy':
                    $this->handleLegalTextUpdate('privacyPolicy');
                    break;
                case 'privacyPolicySocialMedia':
                    $this->handleLegalTextUpdate('privacyPolicySocialMedia');
                    break;
                case 'all':
                    $this->handleLegalTextUpdate('imprint');
                    $this->handleLegalTextUpdate('privacyPolicy');
                    $this->handleLegalTextUpdate('privacyPolicySocialMedia');
                    break;
                default:
                    $this->sendJson(400, ['error' => 'Invalid type']);
                    return;
            }
            
            // record webhook success
            $this->setModuleSetting('last_webhook_status', 'success');
            $this->setModuleSetting('last_webhook_time', time());
            $this->sendJson(200, ['status' => 200, 'message' => 'Success']);
            
        } catch(\Exception $e) {
            $this->log("eRecht24 webhook error: " . $e->getMessage());
            // record webhook failure
            $this->setModuleSetting('last_webhook_status', 'error');
            $this->setModuleSetting('last_webhook_time', time());
            $this->sendJson(500, ['error' => 'Internal server error']);
        }
    }

    /**
     * Validate webhook request with timestamp check
     */
    protected function validateWebhookRequest() {
        $input = $this->wire('input');
        $cache = $this->wire('cache');
        
        $type = (string) $input->get('erecht24_type');
        $secret = (string) ($input->get('erecht24_secret') ?: $this->getServerHeader('HTTP_X_ERECHT24_SECRET'));
        $timestamp = (string) ($input->get('erecht24_timestamp') ?: $this->getServerHeader('HTTP_X_ER24_TIMESTAMP'));
        $nonce = (string) ($input->get('erecht24_nonce') ?: $this->getServerHeader('HTTP_X_ER24_NONCE'));
        
        if(!$type || !in_array($type, self::ALLOWED_PUSH_TYPES, true)) {
            return false;
        }
        
        $webhookSecret = (string) $this->getModuleSetting('webhook_secret');
        if(!$webhookSecret || $webhookSecret !== $secret) {
            return false;
        }
        
        // For non-ping events, require timestamp + nonce with replay protection
        if($type !== 'ping') {
            if(!$timestamp || !$nonce) return false;
            $currentTime = time();
            $requestTime = (int) $timestamp;
            if(abs($currentTime - $requestTime) > 300) {
                $this->log('eRecht24 webhook timestamp validation failed: outside tolerance');
                return false;
            }
            $nonceKey = 'erecht24_nonce_' . sha1($nonce);
            if($cache->get($nonceKey)) {
                $this->log('eRecht24 webhook replay detected for nonce');
                return false;
            }
            // store nonce for 10 minutes
            $cache->save($nonceKey, 1, 600);
        }
        
        return true;
    }

    /**
     * Handle ping request
     */
    protected function handlePing() {
        $this->sendJson(200, ['code' => 200, 'message' => 'pong']);
    }

    /**
     * Handle legal text update
     *
     * @param string $type Legal text type (imprint, privacyPolicy, privacyPolicySocialMedia)
     * @throws WireException If API key is not configured
     */
    public function handleLegalTextUpdate($type) {
        $apiKey = $this->getModuleSetting('api_key');
        
        if(!$apiKey) {
            throw new WireException('eRecht24 API key not configured');
        }
        
        // Fetch legal text from API
        $legalText = $this->fetchLegalText($type, $apiKey);
        
        if($legalText) {
            $this->createLegalTextPage($type, $legalText);
        }
    }

    /**
     * Fetch legal text from eRecht24 API
     *
     * @param string $type Legal text type
     * @param string $apiKey eRecht24 API key
     * @return array|null Legal text data or null on error
     */
    protected function fetchLegalText($type, $apiKey) {
        $endpoint = '';
        switch($type) {
            case 'imprint':
                $endpoint = '/v1/imprint';
                break;
            case 'privacyPolicy':
                $endpoint = '/v1/privacyPolicy';
                break;
            case 'privacyPolicySocialMedia':
                $endpoint = '/v1/privacyPolicySocialMedia';
                break;
            default:
                return null;
        }
        
        $url = $this->getApiHost() . $endpoint;
        $timeout = (int) $this->getHttpTimeout();
        $retries = (int) $this->getHttpRetries();
        $backoffMs = 300; // initial backoff
        
        for($attempt = 0; $attempt <= $retries; $attempt++) {
            $http = new WireHttp();
            if(method_exists($http, 'setTimeout')) {
                $http->setTimeout($timeout);
            }
            $http->setHeaders([
                'eRecht24' => $apiKey,
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache'
            ]);
            
            $response = $http->get($url);
            $code = (int) $http->getHttpCode();
            
            if($code === 200) {
                $data = json_decode($response, true);
                if(!$data || !isset($data['html_de'])) {
                    $this->log('eRecht24 API error: Invalid response format');
                    return null;
                }
                return $data;
            }
            
            // Retry on 429 and 5xx
            if($attempt < $retries && ($code === 429 || $code >= 500 || $code === 0)) {
                usleep($backoffMs * 1000);
                $backoffMs = min($backoffMs * 2, 4000);
                continue;
            }
            
            $this->log("eRecht24 API error: HTTP $code on $url");
            return null;
        }
        
        return null;
    }

    /**
     * Create a new page with legal text content
     *
     * @param string $type Legal text type
     * @param array $legalText Legal text data from API
     * @return Page Created or updated page
     * @throws WireException If legal-text template is not found
     */
    protected function createLegalTextPage($type, $legalText) {
        $pages = $this->wire('pages');
        $templates = $this->wire('templates');
        $database = $this->wire('database');
        
        // Ensure legal-text template exists
        $template = $templates->get('legal-text');
        if(!$template) {
            throw new WireException('Template "legal-text" not found. Please create it first.');
        }
        
        // Get configured parent page
        $parentPageId = $this->getModuleSetting('parent_page') ?: 1;
        $parentPage = $pages->get($parentPageId);
        if(!$parentPage->id) {
            $parentPage = $pages->get('/');
        }
        
        $date = date('Y-m-d H:i:s');
        $typeName = self::LEGAL_TEXT_TYPES[$type] ?? $type;
        
        // Idempotency: compute content hash and skip if identical content already exists for this type
        $contentHash = sha1($type . '|' . ($legalText['html_de'] ?? '') . '|' . ($legalText['html_en'] ?? ''));
        $existing = $pages->find("parent=$parentPage, template=legal-text, legal_type=$type, include=unpublished, sort=-created, limit=20");
        foreach($existing as $ex) {
            $deSame = !$template->hasField('legal_content_de') || $ex->legal_content_de === ($legalText['html_de'] ?? '');
            $enSame = !$template->hasField('legal_content_en') || $ex->legal_content_en === ($legalText['html_en'] ?? '');
            if($deSame && $enSame) {
                $this->log("Skipped creating duplicate legal text page (idempotent): {$ex->path}");
                // update last sync timestamp
                $this->setModuleSetting('last_sync_' . $type, time());
                return $ex;
            }
        }
        
        // Page name with type and timestamp for uniqueness
        $pageName = date('Y-m-d-His') . '-' . $this->sanitizer->pageName($typeName);
        
        // Create new or update existing with same name today if any
        $existingPage = $parentPage->child("name=$pageName");
        $page = $existingPage && $existingPage->id ? $existingPage : new Page();
        
        try {
            $database->beginTransaction();
            if(!$page->id) {
                $page->template = $template;
                $page->parent = $parentPage;
                $page->name = $pageName;
                $page->status(Page::statusUnpublished);
            }
            // Set page title
            $page->title = $typeName . ' - ' . $date;
            
            // Set legal text content (assuming fields exist on template)
            if($template->hasField('legal_content_de')) {
                $page->legal_content_de = $legalText['html_de'];
            }
            if($template->hasField('legal_content_en') && isset($legalText['html_en'])) {
                $page->legal_content_en = $legalText['html_en'];
            }
            if($template->hasField('legal_type')) {
                $page->legal_type = $type;
            }
            if($template->hasField('legal_date')) {
                $page->legal_date = $date;
            }
            // Save the page
            $page->save();
            $database->commit();
        } catch(\Exception $e) {
            if($database->inTransaction()) $database->rollBack();
            throw $e;
        }
        
        // update last sync timestamp
        $this->setModuleSetting('last_sync_' . $type, time());
        
        $this->log("Created/updated legal text page: {$page->path} (hash=$contentHash)");
        
        return $page;
    }

    /**
     * Helper: unified JSON response output
     */
    protected function sendJson(int $statusCode, array $payload): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    /**
     * Helper: get request method uppercased
     */
    protected function getRequestMethod(): string {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Helper: safe server header fetch
     */
    protected function getServerHeader(string $key): ?string {
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    /**
     * Helper: API host with config override (e.g. $config->erecht24_api_host)
     */
    protected function getApiHost(): string {
        $cfg = $this->wire('config');
        $host = isset($cfg->erecht24_api_host) ? (string) $cfg->erecht24_api_host : '';
        return $host ?: self::API_HOST;
    }

    /**
     * Helper: HTTP timeout (seconds), override via $config->erecht24_http_timeout
     */
    protected function getHttpTimeout(): int {
        $cfg = $this->wire('config');
        return isset($cfg->erecht24_http_timeout) ? (int) $cfg->erecht24_http_timeout : 10;
    }

    /**
     * Helper: HTTP retries, override via $config->erecht24_http_retries
     */
    protected function getHttpRetries(): int {
        $cfg = $this->wire('config');
        return isset($cfg->erecht24_http_retries) ? (int) $cfg->erecht24_http_retries : 2;
    }

    /**
     * Public preview helper for admin dry-run: fetch legal text without saving
     *
     * @param string $type
     * @return array|null
     */
    public function getLegalTextPreview(string $type) {
        $apiKey = (string) $this->getModuleSetting('api_key');
        if(!$apiKey) return null;
        return $this->fetchLegalText($type, $apiKey);
    }

    /**
     * Configuration fields for module
     */
    public static function getModuleConfigInputfields(array $data) {
        $inputfields = new InputfieldWrapper();
        $modules = wire('modules');
        $config = wire('config');
        
        // Configuration note
        $field = $modules->get('InputfieldMarkup');
        $field->name = 'config_note';
        $field->label = 'Configuration Storage';
        $field->description = 'Configuration can be set here or overridden in site/config.php';
        $field->value = '<div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0; border-radius: 4px;">';
        $field->value .= '<strong>📝 Note:</strong> You can override these settings in site/config.php using: <code>$config->erecht24_api_key</code>, <code>$config->erecht24_webhook_secret</code>, etc.';
        $field->value .= '</div>';
        $inputfields->add($field);
        
        // API Key field
        $field = $modules->get('InputfieldText');
        $field->name = 'api_key';
        $field->label = 'eRecht24 API Key';
        $field->description = 'Enter your eRecht24 API key';
        $field->columnWidth = 100;
        $field->required = false;
        
        // Check for config.php override
        if(isset($config->erecht24_api_key)) {
            $field->value = $config->erecht24_api_key;
            $field->attr('readonly', true);
            $field->description .= ' (Read-only: Set in site/config.php)';
        } else {
            $field->value = isset($data['api_key']) ? $data['api_key'] : '';
        }
        $inputfields->add($field);
        
        // Webhook Secret field
        $field = $modules->get('InputfieldText');
        $field->name = 'webhook_secret';
        $field->label = 'Webhook Secret';
        $field->description = 'Webhook secret for API communication';
        $field->columnWidth = 50;
        $field->required = false;
        
        if(isset($config->erecht24_webhook_secret)) {
            $field->value = $config->erecht24_webhook_secret;
            $field->attr('readonly', true);
            $field->description .= ' (Read-only: Set in site/config.php)';
        } else {
            $field->value = isset($data['webhook_secret']) ? $data['webhook_secret'] : '';
        }
        $inputfields->add($field);
        
        // Generate webhook secret button (only if not in config.php)
        if(!isset($config->erecht24_webhook_secret)) {
            $field = $modules->get('InputfieldMarkup');
            $field->name = 'generate_webhook';
            $field->label = 'Generate New Webhook Secret';
            $field->columnWidth = 50;
            $field->value = '<button type="button" onclick="document.querySelector(\'input[name=webhook_secret]\').value=\'' . bin2hex(random_bytes(32)) . '\'" class="ui-button">Generate New Secret</button>';
            $inputfields->add($field);
        }
        
        // Parent Page field
        $field = $modules->get('InputfieldPageListSelect');
        $field->name = 'parent_page';
        $field->label = 'Parent Page for Legal Texts';
        $field->description = 'Select the parent page where legal text pages will be created';
        $field->value = isset($data['parent_page']) ? $data['parent_page'] : 1; // Default to home page
        $field->parent_id = 0;
        $field->columnWidth = 100;
        $inputfields->add($field);
        
        // Client ID field (read-only)
        $field = $modules->get('InputfieldText');
        $field->name = 'client_id';
        $field->label = 'Client ID (Auto-populated)';
        $field->description = 'Client ID will be automatically set when registering with eRecht24 API';
        $field->attr('readonly', true);
        $field->columnWidth = 100;
        $clientId = isset($data['client_id']) ? $data['client_id'] : null;
        $field->value = (!empty($clientId) && $clientId !== 'Not registered yet') ? $clientId : 'Not registered yet';
        $inputfields->add($field);
        
        // Webhook URL info
        $field = $modules->get('InputfieldMarkup');
        $field->name = 'webhook_info';
        $field->label = 'Webhook URL';
        $field->description = 'Use this URL in your eRecht24 project settings:';
        $webhookUrl = wire('config')->urls->root . 'erecht24-webhook/';
        $field->value = "<code style='background: #f5f5f5; padding: 5px; display: block; margin: 5px 0;'>{$webhookUrl}</code>";
        $inputfields->add($field);
        
        // Registration status
        $field = $modules->get('InputfieldMarkup');
        $field->name = 'registration_info';
        $field->label = 'API Client Registration';
        $clientId = isset($data['client_id']) ? $data['client_id'] : null;
        $isRegistered = !empty($clientId) && $clientId !== 'Not registered yet';
        
        if(!$isRegistered) {
            $field->description = 'Your ProcessWire installation needs to be registered as an API client with eRecht24.';
            $field->value = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0;">';
            $field->value .= '⚠ <strong>Registration Required:</strong> Please register your installation with eRecht24.<br>';
            $field->value .= 'Go to <strong>Setup > eRecht24 Legal Texts</strong> and click "Bei eRecht24 registrieren".';
            $field->value .= '</div>';
        } else {
            $field->value = '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0;">';
            $field->value .= '✓ <strong>Registered:</strong> Client ID ' . $clientId;
            $field->value .= '</div>';
        }
        
        $inputfields->add($field);
        
        return $inputfields;
    }


    /**
     * Initialize the module
     */
    public function init() {
        // Handle webhook requests with dedicated URL hook
        $this->addHook('/erecht24-webhook', $this, 'handleWebhook');
    }


    /**
     * Install method
     */
    public function ___install() {
        // Migrate existing config table data if it exists
        $this->migrateOldConfig();
        
        // Install the ProcessErecht24 module for admin interface
        $modules = $this->wire('modules');
        
        if(!$modules->isInstalled('ProcessErecht24')) {
            $modules->install('ProcessErecht24');
            $this->message('ProcessErecht24 admin interface installed.');
        }
        
        // Auto-generate webhook secret if not already set
        if(!$this->getModuleSetting('webhook_secret')) {
            $this->setModuleSetting('webhook_secret', bin2hex(random_bytes(32)));
            $this->message('Webhook secret auto-generated.');
        }
        
        $this->message('eRecht24 module installed successfully. Please configure your API key.');
    }

    /**
     * Migrate configuration from old database table to module config
     */
    protected function migrateOldConfig() {
        $database = $this->wire('database');
        
        try {
            // Check if old config table exists
            $result = $database->query("SHOW TABLES LIKE 'erecht24_config'");
            if(!$result->rowCount()) {
                return; // No old table to migrate
            }
            
            // Migrate existing settings
            $query = $database->prepare('SELECT setting_name, setting_value FROM erecht24_config');
            $query->execute();
            
            $moduleConfig = [];
            while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $moduleConfig[$row['setting_name']] = $row['setting_value'];
            }
            
            if(!empty($moduleConfig)) {
                $this->wire('modules')->saveConfig($this, $moduleConfig);
                $this->message('Migrated configuration from old database table.');
            }
            
        } catch(\Exception $e) {
            $this->log("Error migrating old config: " . $e->getMessage());
        }
    }


    /**
     * Uninstall method
     */
    public function ___uninstall() {
        // Uninstall ProcessErecht24 module
        $modules = $this->wire('modules');
        
        if($modules->isInstalled('ProcessErecht24')) {
            $modules->uninstall('ProcessErecht24');
        }
        
        // Remove old configuration table if it exists
        $database = $this->wire('database');
        try {
            $database->exec('DROP TABLE IF EXISTS erecht24_config');
            $this->message('eRecht24 configuration table removed.');
        } catch(\Exception $e) {
            // Table might not exist, that's OK
        }
    }
}