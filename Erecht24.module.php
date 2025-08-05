<?php namespace ProcessWire;

/**
 * eRecht24 Legal Text Module for ProcessWire
 * 
 * Integrates with eRecht24 API to synchronize legal texts (imprint, privacy policy)
 * and automatically creates pages with legal content.
 * 
 * @version 1.0.0
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

    public static function getModuleInfo() {
        return [
            'title' => 'eRecht24 Legal Texts',
            'summary' => 'Integrates with eRecht24 API to synchronize legal texts and create pages automatically',
            'version' => '1.2.0',
            'author' => 'ProcessWire Module',
            'autoload' => true,
            'singular' => true,
            'requires' => 'ProcessWire>=3.0.0',
            'icon' => 'legal'
        ];
    }


    /**
     * Get a module setting from custom database table
     */
    protected function getModuleSetting($key) {
        $database = $this->wire('database');
        
        try {
            $query = $database->prepare('SELECT setting_value FROM erecht24_config WHERE setting_name = ?');
            $query->execute([$key]);
            $result = $query->fetch(\PDO::FETCH_ASSOC);
            
            return $result ? $result['setting_value'] : null;
            
        } catch(\Exception $e) {
            $this->log("Error retrieving module setting '$key': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set a module setting in custom database table
     */
    protected function setModuleSetting($key, $value) {
        $database = $this->wire('database');
        
        try {
            // Use INSERT ... ON DUPLICATE KEY UPDATE for MySQL
            $query = $database->prepare(
                'INSERT INTO erecht24_config (setting_name, setting_value) 
                 VALUES (?, ?) 
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), modified = CURRENT_TIMESTAMP'
            );
            $query->execute([$key, $value]);
            
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
        $database = $this->wire('database');
        $settings = [];
        
        try {
            $query = $database->prepare('SELECT setting_name, setting_value FROM erecht24_config');
            $query->execute();
            
            while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $settings[$row['setting_name']] = $row['setting_value'];
            }
            
        } catch(\Exception $e) {
            $this->log("Error retrieving all module settings: " . $e->getMessage());
        }
        
        return $settings;
    }

    /**
     * Delete a module setting from custom database table
     */
    protected function deleteModuleSetting($key) {
        $database = $this->wire('database');
        
        try {
            $query = $database->prepare('DELETE FROM erecht24_config WHERE setting_name = ?');
            $query->execute([$key]);
            return true;
            
        } catch(\Exception $e) {
            $this->log("Error deleting module setting '$key': " . $e->getMessage());
            return false;
        }
    }


    /**
     * Check if this is a webhook request and handle it
     */
    public function checkWebhookRequest(HookEvent $event) {
        $input = $this->wire('input');
        
        // Check if this is a webhook request based on URL and parameters
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        if(strpos($requestUri, '/erecht24-webhook') !== false && $input->get('erecht24_type')) {
            try {
                $this->processWebhook();
            } catch(\Exception $e) {
                $this->log("eRecht24 webhook error: " . $e->getMessage());
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Internal server error']);
            }
            exit; // Stop further processing
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
     * Validate webhook request
     */
    protected function validateWebhookRequest() {
        $input = $this->wire('input');
        
        $secret = $input->get('erecht24_secret');
        $type = $input->get('erecht24_type');
        
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
     */
    protected function createLegalTextPage($type, $legalText) {
        $pages = $this->wire('pages');
        $templates = $this->wire('templates');
        
        // Ensure legal-text template exists
        $template = $templates->get('legal-text');
        if(!$template) {
            throw new WireException('Template "legal-text" not found. Please create it first.');
        }
        
        // Create page name with date and type
        $date = date('Y-m-d');
        $typeName = self::LEGAL_TEXT_TYPES[$type] ?? $type;
        $pageName = $date . '-' . $this->sanitizer->pageName($typeName);
        
        // Check if page already exists today
        $homePage = $pages->get('/');
        $existingPage = $homePage->child("name=$pageName");
        
        if($existingPage->id) {
            // Update existing page
            $page = $existingPage;
        } else {
            // Create new page
            $page = new Page();
            $page->template = $template;
            $page->parent = $homePage;
            $page->name = $pageName;
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
        $erecht24 = wire('modules')->get('Erecht24');
        
        
        // Configuration note
        $field = $modules->get('InputfieldMarkup');
        $field->name = 'config_note';
        $field->label = 'Configuration Storage';
        $field->description = 'All eRecht24 configuration is stored in a custom database table for security and production compatibility.';
        $field->value = '<div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0; border-radius: 4px;">';
        $field->value .= '<strong>📝 Note:</strong> Configuration values are stored in a dedicated database table (erecht24_config). ';
        $field->value .= 'Edit the values below and click "Submit" to save them to the database.';
        $field->value .= '</div>';
        $inputfields->add($field);
        
        // API Key field (editable)
        $field = $modules->get('InputfieldText');
        $field->name = 'api_key';
        $field->label = 'eRecht24 API Key';
        $field->description = 'Enter your eRecht24 API key';
        $field->columnWidth = 100;
        $field->required = false;
        $apiKey = $erecht24->getModuleSetting('api_key');
        $field->value = $apiKey ?: '';
        $inputfields->add($field);
        
        // Webhook Secret field (editable with generate button)
        $field = $modules->get('InputfieldText');
        $field->name = 'webhook_secret';
        $field->label = 'Webhook Secret';
        $field->description = 'Enter webhook secret or leave empty to auto-generate';
        $field->columnWidth = 50;
        $field->required = false;
        $webhookSecret = $erecht24->getModuleSetting('webhook_secret');
        $field->value = $webhookSecret ?: '';
        $inputfields->add($field);
        
        // Generate webhook secret button
        $field = $modules->get('InputfieldMarkup');
        $field->name = 'generate_webhook';
        $field->label = 'Generate New Webhook Secret';
        $field->columnWidth = 50;
        $field->value = '<button type="button" onclick="document.querySelector(\'input[name=webhook_secret]\').value=\'' . bin2hex(random_bytes(32)) . '\'" class="ui-button">Generate New Secret</button>';
        $inputfields->add($field);
        
        // Client ID field (read-only)
        $field = $modules->get('InputfieldText');
        $field->name = 'client_id_display';
        $field->label = 'Client ID (Auto-populated)';
        $field->description = 'Client ID will be automatically set when registering with eRecht24 API';
        $field->attr('readonly', true);
        $field->columnWidth = 100;
        $clientId = $erecht24->getModuleSetting('client_id');
        $field->value = $clientId ? $clientId : 'Not registered yet';
        $inputfields->add($field);
        
        // Webhook URL info
        $field = $modules->get('InputfieldMarkup');
        $field->name = 'webhook_info';
        $field->label = 'Webhook URL';
        $field->description = 'Use this URL in your eRecht24 project settings:';
        $webhookUrl = wire('config')->urls->root . 'erecht24-webhook/';
        $field->value = "<code style='background: #f5f5f5; padding: 5px; display: block; margin: 5px 0;'>{$webhookUrl}</code>";
        $inputfields->add($field);
        
        // Registration status and button
        $field = $modules->get('InputfieldMarkup');
        $field->name = 'registration_info';
        $field->label = 'API Client Registration';
        $clientId = $erecht24->getModuleSetting('client_id');
        
        if(!$clientId) {
            $field->description = 'Your ProcessWire installation needs to be registered as an API client with eRecht24.';
            $field->value = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0;">';
            $field->value .= '<strong>Registration Required:</strong> Please register your installation with eRecht24.<br>';
            $field->value .= 'Go to <strong>Setup > eRecht24 Legal Texts</strong> and click "Bei eRecht24 registrieren".<br>';
            $field->value .= '<small>If the admin page is not available, refresh modules or install "eRecht24 Admin" manually.</small>';
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
     * Hook into ProcessWire's module config save process
     */
    public function init() {
        // Handle webhook requests early in the process
        $this->addHookBefore('ProcessWire::ready', $this, 'checkWebhookRequest');
        
        // Hook into module config save process
        $this->addHookAfter('Modules::saveConfig', $this, 'hookModuleConfigSave');
    }
    
    /**
     * Hook method to intercept module config saves for this module
     */
    public function hookModuleConfigSave(HookEvent $event) {
        $className = $event->arguments(0);
        $configData = $event->arguments(1);
        
        // Only handle saves for this module
        if(is_string($className)) {
            $moduleClass = $className;
        } else if(is_object($className)) {
            $moduleClass = $className->className();
        } else {
            return;
        }
        
        if($moduleClass !== 'Erecht24') {
            return;
        }
        
        // Save to our database instead
        if(isset($configData['api_key'])) {
            $this->setModuleSetting('api_key', $configData['api_key']);
        }
        
        if(isset($configData['webhook_secret'])) {
            if($configData['webhook_secret']) {
                $this->setModuleSetting('webhook_secret', $configData['webhook_secret']);
            } else {
                // Auto-generate if empty
                $webhookSecret = bin2hex(random_bytes(32));
                $this->setModuleSetting('webhook_secret', $webhookSecret);
            }
        }
        
        // Prevent ProcessWire from saving to its own config system
        $event->replace = true;
        $event->return = true;
    }


    /**
     * Install method
     */
    public function ___install() {
        // Create database table for configuration storage
        $this->createConfigTable();
        
        // Install the ProcessErecht24 module for admin interface
        $modules = $this->wire('modules');
        
        if(!$modules->isInstalled('ProcessErecht24')) {
            $modules->install('ProcessErecht24');
            $this->message('ProcessErecht24 admin interface installed.');
        }
        
        // Auto-generate webhook secret on install
        $this->autoGenerateWebhookSecret();
        
        $this->message('eRecht24 module installed successfully. Please configure your API key and visit Setup > eRecht24 Legal Texts.');
    }

    /**
     * Create the configuration database table
     */
    protected function createConfigTable() {
        $database = $this->wire('database');
        
        $sql = "CREATE TABLE IF NOT EXISTS erecht24_config (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            setting_name varchar(255) NOT NULL,
            setting_value text,
            created timestamp DEFAULT CURRENT_TIMESTAMP,
            modified timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_name (setting_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        try {
            $database->exec($sql);
            $this->message('eRecht24 configuration table created successfully.');
        } catch(\Exception $e) {
            throw new WireException('Failed to create eRecht24 configuration table: ' . $e->getMessage());
        }
    }

    /**
     * Auto-generate webhook secret if not exists
     */
    protected function autoGenerateWebhookSecret() {
        if(!$this->getModuleSetting('webhook_secret')) {
            $webhookSecret = bin2hex(random_bytes(32));
            $this->setModuleSetting('webhook_secret', $webhookSecret);
            $this->message('Webhook secret auto-generated.');
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
        
        // Remove configuration table
        $database = $this->wire('database');
        try {
            $database->exec('DROP TABLE IF EXISTS erecht24_config');
            $this->message('eRecht24 configuration table removed.');
        } catch(\Exception $e) {
            $this->error('Error removing eRecht24 configuration table: ' . $e->getMessage());
        }
    }
}