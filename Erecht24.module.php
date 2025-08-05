<?php namespace ProcessWire;

/**
 * eRecht24 Legal Text Module for ProcessWire
 * 
 * Integrates with eRecht24 API to synchronize legal texts (imprint, privacy policy)
 * and automatically creates pages with legal content.
 * 
 * @version 0.2.0
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
        
        if(!$input->get('erecht24_type')) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing erecht24_type parameter']);
            return;
        }
        
        try {
            $this->processWebhook();
        } catch(\Exception $e) {
            $this->log("eRecht24 webhook error: " . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal server error']);
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
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
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
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Invalid type']);
                    return;
            }
            
            header('Content-Type: application/json');
            echo json_encode(['status' => 200, 'message' => 'Success']);
            
        } catch(\Exception $e) {
            $this->log("eRecht24 webhook error: " . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    /**
     * Validate webhook request with timestamp check
     */
    protected function validateWebhookRequest() {
        $input = $this->wire('input');
        
        $secret = $input->get('erecht24_secret');
        $type = $input->get('erecht24_type');
        $timestamp = $input->get('erecht24_timestamp');
        
        if(!$secret || !$type) {
            return false;
        }
        
        if(!in_array($type, self::ALLOWED_PUSH_TYPES)) {
            return false;
        }
        
        $webhookSecret = $this->getModuleSetting('webhook_secret');
        if(!$webhookSecret || $webhookSecret !== $secret) {
            return false;
        }
        
        // Optional timestamp validation (prevent replay attacks)
        if($timestamp) {
            $currentTime = time();
            $requestTime = (int)$timestamp;
            // Allow requests within 5 minutes
            if(abs($currentTime - $requestTime) > 300) {
                $this->log("eRecht24 webhook timestamp validation failed: too old");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Handle ping request
     */
    protected function handlePing() {
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'pong']);
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
        $http = new WireHttp();
        
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
        
        $url = self::API_HOST . $endpoint;
        
        $http->setHeaders([
            'eRecht24' => $apiKey,
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache'
        ]);
        
        $response = $http->get($url);
        
        if($http->getHttpCode() !== 200) {
            $this->log("eRecht24 API error: HTTP " . $http->getHttpCode());
            return null;
        }
        
        $data = json_decode($response, true);
        
        if(!$data || !isset($data['html_de'])) {
            $this->log("eRecht24 API error: Invalid response format");
            return null;
        }
        
        return $data;
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
        
        // Create page name with date and type
        $date = date('Y-m-d H:i:s');
        $typeName = self::LEGAL_TEXT_TYPES[$type] ?? $type;
        $pageName = $date . '-' . $this->sanitizer->pageName($typeName);
        
        // Check if page already exists today
        $existingPage = $parentPage->child("name=$pageName");
        
        if($existingPage->id) {
            // Update existing page
            $page = $existingPage;
        } else {
            // Create new page
            $page = new Page();
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
        
        $this->log("Created/updated legal text page: {$page->path}");
        
        return $page;
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