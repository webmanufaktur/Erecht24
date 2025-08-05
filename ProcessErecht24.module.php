<?php namespace ProcessWire;

/**
 * ProcessErecht24 - Admin interface for eRecht24 module
 */

class ProcessErecht24 extends Process implements Module {

    public static function getModuleInfo() {
        return [
            'title' => 'eRecht24 Admin',
            'summary' => 'Admin interface for managing eRecht24 legal texts',
            'version' => '1.1.0',
            'author' => 'ProcessWire Module',
            'requires' => 'Erecht24',
            'icon' => 'legal',
            'page' => [
                'name' => 'erecht24',
                'parent' => 'setup',
                'title' => 'eRecht24 Legal Texts'
            ],
            // 'useNavJSON' => true,
            // 'nav' => [
            //     ['url' => '', 'label' => 'Dashboard', 'icon' => 'dashboard']
            // ]
        ];
    }

    public function init() {
        parent::init();
    }

    /**
     * Main admin page
     */
    public function ___execute() {
        // Handle POST requests first
        if($this->input->post('action')) {
            return $this->handleFormSubmission();
        }
        return $this->renderPage();
    }
    
    /**
     * Render the main admin page
     */
    protected function renderPage() {
        $pages = $this->wire('pages');
        $erecht24 = $this->wire('modules')->get('Erecht24');
        
        $out = '<h2>eRecht24 Legal Texts</h2>';
        
        // Check if module is configured
        $apiKey = $erecht24->getModuleSetting('api_key');
        if(!$apiKey) {
            $out .= '<div class="NoticeWarning">';
            $out .= '<p>Das eRecht24 Modul ist noch nicht konfiguriert.</p>';
            $out .= '<p><a href="' . $this->config->urls->admin . 'module/edit?name=Erecht24" class="ui-button">Jetzt konfigurieren</a></p>';
            $out .= '</div>';
            return $out;
        }
        
        // Show configuration info
        $out .= '<div class="uk-grid">';
        $out .= '<div class="uk-width-2-3">';
        
        // Recent legal text pages
        $out .= '<h3>Aktuelle Rechtstexte</h3>';
        $legalPages = $pages->find("template=legal-text, sort=-created, limit=10");
        
        if($legalPages->count()) {
            $out .= '<table class="AdminDataTable">';
            $out .= '<thead><tr><th>Titel</th><th>Typ</th><th>Datum</th><th>Erstellt</th><th>Aktionen</th></tr></thead>';
            $out .= '<tbody>';
            
            foreach($legalPages as $legalPage) {
                $out .= '<tr>';
                $out .= '<td><a href="' . $legalPage->editURL . '">' . $legalPage->title . '</a></td>';
                $out .= '<td>' . ($legalPage->legal_type ?: '-') . '</td>';
                $out .= '<td>' . ($legalPage->legal_date ? date('d.m.Y', $legalPage->legal_date) : '-') . '</td>';
                $out .= '<td>' . date('d.m.Y', $legalPage->created) . '</td>';
                $out .= '<td>';
                $out .= '<a href="' . $legalPage->url . '" target="_blank" class="ui-button ui-button-small">Ansehen</a> ';
                $out .= '<a href="' . $legalPage->editURL . '" class="ui-button ui-button-small">Bearbeiten</a>';
                $out .= '</td>';
                $out .= '</tr>';
            }
            
            $out .= '</tbody></table>';
        } else {
            $out .= '<p>Noch keine Rechtstexte vorhanden.</p>';
        }
        
        // API Client Registration
        $out .= '<h3>API Client Registration</h3>';
        $clientId = $erecht24->getModuleSetting('client_id');
        if(!$clientId) {
            $out .= '<div class="NoticeWarning">';
            $out .= '<p>Ihr ProcessWire-System ist noch nicht als API-Client bei eRecht24 registriert.</p>';
            $out .= '<form method="post" action="./">';
            $out .= $this->session->CSRF->renderInput();
            $out .= '<input type="hidden" name="action" value="register_client">';
            $out .= '<input type="submit" class="ui-button ui-button-primary" value="Bei eRecht24 registrieren">';
            $out .= '</form>';
            $out .= '</div>';
        } else {
            $out .= '<div class="NoticeMessage">';
            $out .= '<p>✓ Ihr System ist als API-Client registriert (ID: ' . $clientId . ')</p>';
            $out .= '</div>';
        }

        // Configuration section
        $out .= '<h3>Konfiguration</h3>';
        
        // API Key form
        $out .= '<form method="post" action="./">';
        $out .= $this->session->CSRF->renderInput();
        $out .= '<input type="hidden" name="action" value="save_api_key">';
        $out .= '<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">';
        $out .= '<p><label for="api_key"><strong>eRecht24 API Key:</strong></label></p>';
        $out .= '<input type="text" name="api_key" id="api_key" value="' . ($apiKey ?: '') . '" placeholder="Geben Sie Ihren API-Schlüssel ein" style="width: 400px;">';
        $out .= '<br><br>';
        $out .= '<input type="submit" class="ui-button ui-button-primary" value="API Key speichern">';
        $out .= '</div>';
        $out .= '</form>';
        
        // Webhook Secret form
        $webhookSecret = $erecht24->getModuleSetting('webhook_secret');
        $out .= '<form method="post" action="./">';
        $out .= $this->session->CSRF->renderInput();
        $out .= '<input type="hidden" name="action" value="save_webhook_secret">';
        $out .= '<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">';
        $out .= '<p><label for="webhook_secret"><strong>Webhook Secret:</strong></label></p>';
        $out .= '<input type="text" name="webhook_secret" id="webhook_secret" value="' . ($webhookSecret ?: '') . '" placeholder="Webhook Secret" style="width: 400px;">';
        $out .= '<br><br>';
        $out .= '<input type="submit" class="ui-button ui-button-primary" value="Webhook Secret speichern">';
        $out .= ' <input type="submit" name="generate_new" class="ui-button" value="Neues Secret generieren">';
        $out .= '</div>';
        $out .= '</form>';
        $out .= '<br>';

        // Manual sync buttons
        $out .= '<h3>Manuelle Synchronisation</h3>';
        $out .= '<p>Sie können die Rechtstexte manuell von eRecht24 abrufen:</p>';
        
        $out .= '<form method="post" action="./">';
        $out .= $this->session->CSRF->renderInput();
        $out .= '<input type="hidden" name="action" value="sync_legal_text">';
        $out .= '<select name="sync_type" required>';
        $out .= '<option value="">Typ auswählen...</option>';
        $out .= '<option value="imprint">Impressum</option>';
        $out .= '<option value="privacyPolicy">Datenschutzerklärung</option>';
        $out .= '<option value="privacyPolicySocialMedia">Datenschutzerklärung Social Media</option>';
        $out .= '<option value="all">Alle Rechtstexte</option>';
        $out .= '</select>';
        $out .= '<input type="submit" class="ui-button ui-button-primary" value="Synchronisieren">';
        $out .= '</form>';
        
        $out .= '</div>';
        
        // Sidebar with configuration info
        $out .= '<div class="uk-width-1-3">';
        $out .= '<h3>Konfiguration</h3>';
        
        $out .= '<div class="uk-panel uk-panel-box">';
        $out .= '<h4>API-Konfiguration</h4>';
        $out .= '<p><strong>API Key:</strong> ' . ($apiKey ? '••••••••' : 'Nicht konfiguriert') . '</p>';
        $webhookSecret = $erecht24->getModuleSetting('webhook_secret');
        $out .= '<p><strong>Webhook Secret:</strong> ' . ($webhookSecret ? '••••••••' : 'Nicht konfiguriert') . '</p>';
        $out .= '<p><a href="' . $this->config->urls->admin . 'module/edit?name=Erecht24" class="ui-button">Einstellungen bearbeiten</a></p>';
        $out .= '</div>';
        
        $out .= '<div class="uk-panel uk-panel-box">';
        $out .= '<h4>Webhook URL</h4>';
        $webhookUrl = $this->wire('config')->urls->root . 'erecht24-webhook/';
        $out .= '<p>Verwenden Sie diese URL in Ihren eRecht24 Projekteinstellungen:</p>';
        $out .= '<code style="word-break: break-all; background: #f5f5f5; padding: 5px; display: block;">' . $webhookUrl . '</code>';
        $out .= '<p><small>Hinweis: Die URL funktioniert auch wenn keine entsprechende Seite existiert.</small></p>';
        $out .= '</div>';
        
        $out .= '<div class="uk-panel uk-panel-box">';
        $out .= '<h4>Template-Informationen</h4>';
        $template = $this->templates->get('legal-text');
        if($template) {
            $out .= '<p><strong>Template:</strong> legal-text ✓</p>';
            $out .= '<p><strong>Benötigte Felder:</strong></p>';
            $out .= '<ul>';
            $out .= '<li>legal_content_de (Textarea)</li>';
            $out .= '<li>legal_content_en (Textarea)</li>';
            $out .= '<li>legal_type (Text)</li>';
            $out .= '<li>legal_date (Text/Date)</li>';
            $out .= '</ul>';
        } else {
            $out .= '<p><strong>Template:</strong> legal-text ❌</p>';
            $out .= '<p class="NoticeWarning">Das Template "legal-text" wurde nicht gefunden. Bitte erstellen Sie es in der Template-Verwaltung.</p>';
        }
        $out .= '</div>';
        
        $out .= '</div>';
        $out .= '</div>';
        
        return $out;
    }

    /**
     * Handle form submissions
     */
    protected function handleFormSubmission() {
        $input = $this->wire('input');
        
        if(!$this->session->CSRF->hasValidToken()) {
            $this->error('CSRF token validation failed');
            return $this->renderPage();
        }
        
        $action = $input->post('action');
        
        if($action === 'sync_legal_text') {
            return $this->syncLegalText();
        }
        
        if($action === 'register_client') {
            return $this->registerClient();
        }
        
        if($action === 'save_api_key') {
            return $this->saveApiKey();
        }
        
        if($action === 'save_webhook_secret') {
            return $this->saveWebhookSecret();
        }
        
        return $this->renderPage();
    }

    /**
     * Manually sync legal text
     */
    protected function syncLegalText() {
        $input = $this->wire('input');
        $erecht24 = $this->wire('modules')->get('Erecht24');
        
        $syncType = $input->post('sync_type');
        
        if(!$syncType) {
            $this->error('Bitte wählen Sie einen Typ zum Synchronisieren aus.');
            return $this->renderPage();
        }
        
        try {
            if($syncType === 'all') {
                $erecht24->handleLegalTextUpdate('imprint');
                $erecht24->handleLegalTextUpdate('privacyPolicy');
                $erecht24->handleLegalTextUpdate('privacyPolicySocialMedia');
                $this->message('Alle Rechtstexte wurden erfolgreich synchronisiert.');
            } else {
                $erecht24->handleLegalTextUpdate($syncType);
                $this->message('Rechtstext wurde erfolgreich synchronisiert.');
            }
            
        } catch(\Exception $e) {
            $this->error('Fehler bei der Synchronisation: ' . $e->getMessage());
        }
        
        return $this->renderPage();
    }

    /**
     * Register API client with eRecht24
     */
    protected function registerClient() {
        $erecht24 = $this->wire('modules')->get('Erecht24');
        $apiKey = $erecht24->getModuleSetting('api_key');
        
        if(!$apiKey) {
            $this->error('API-Schlüssel ist nicht konfiguriert. Bitte konfigurieren Sie zuerst den API-Schlüssel.');
            return $this->renderPage();
        }
        
        try {
            $http = new WireHttp();
            $config = $this->wire('config');
            
            // Prepare client data
            $webhookUrl = $config->urls->httpRoot . 'erecht24-webhook/';
            $webhookSecret = $erecht24->getModuleSetting('webhook_secret') ?? bin2hex(random_bytes(16));
            
            $clientData = [
                'cms' => 'ProcessWire',
                'cms_version' => $config->version,
                'plugin_name' => 'ProcessWire eRecht24 Module',
                'push_uri' => $webhookUrl,
                'push_method' => 'GET',
                'author_mail' => 'admin@' . $_SERVER['SERVER_NAME']
            ];
            
            $http->setHeaders([
                'eRecht24' => $apiKey,
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache'
            ]);
            
            // Use correct v1 API endpoint (plural)
            $url = 'https://api.e-recht24.de/v1/clients';
            
            // Debug: Log the request
            $this->log("eRecht24 registration request URL: $url");
            $this->log("eRecht24 registration request data: " . json_encode($clientData));
            
            $response = $http->post($url, json_encode($clientData));
            
            // Debug: Log the response
            $this->log("eRecht24 registration response code: " . $http->getHttpCode());
            $this->log("eRecht24 registration response: " . $response);
            
            if($http->getHttpCode() === 201 || $http->getHttpCode() === 200) {
                $responseData = json_decode($response, true);
                
                if(isset($responseData['client_id'])) {
                    // Save client ID and webhook secret to database
                    $erecht24->setModuleSetting('client_id', $responseData['client_id']);
                    $erecht24->setModuleSetting('webhook_secret', $webhookSecret);
                    
                    $this->message('API-Client erfolgreich bei eRecht24 registriert! Client ID: ' . $responseData['client_id']);
                } else {
                    $this->error('Unerwartete Antwort von eRecht24 API.');
                }
            } else {
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['message'] ?? 'Unbekannter Fehler';
                $this->error('Fehler bei der Client-Registrierung: ' . $errorMessage . ' (HTTP ' . $http->getHttpCode() . ')');
            }
        } catch(\Exception $e) {
            $this->error('Fehler bei der Client-Registrierung: ' . $e->getMessage());
        }
        
        return $this->renderPage();
    }

    /**
     * Save API Key to database
     */
    protected function saveApiKey() {
        $input = $this->wire('input');
        $erecht24 = $this->wire('modules')->get('Erecht24');
        
        $apiKey = trim($input->post('api_key'));
        
        if(!$apiKey) {
            $this->error('Bitte geben Sie einen gültigen API-Schlüssel ein.');
            return $this->renderPage();
        }
        
        if($erecht24->setModuleSetting('api_key', $apiKey)) {
            $this->message('API-Schlüssel erfolgreich gespeichert.');
        } else {
            $this->error('Fehler beim Speichern des API-Schlüssels.');
        }
        
        return $this->renderPage();
    }

    /**
     * Save Webhook Secret to database
     */
    protected function saveWebhookSecret() {
        $input = $this->wire('input');
        $erecht24 = $this->wire('modules')->get('Erecht24');
        
        // Check if user wants to generate new secret
        if($input->post('generate_new')) {
            $webhookSecret = bin2hex(random_bytes(32));
            $this->message('Neues Webhook Secret generiert.');
        } else {
            $webhookSecret = trim($input->post('webhook_secret'));
            if(!$webhookSecret) {
                $this->error('Bitte geben Sie ein gültiges Webhook Secret ein.');
                return $this->renderPage();
            }
        }
        
        if($erecht24->setModuleSetting('webhook_secret', $webhookSecret)) {
            $this->message('Webhook Secret erfolgreich gespeichert.');
        } else {
            $this->error('Fehler beim Speichern des Webhook Secrets.');
        }
        
        return $this->renderPage();
    }

}