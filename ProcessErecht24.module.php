<?php namespace ProcessWire;

/**
 * ProcessErecht24 - Admin interface for eRecht24 module
 */

class ProcessErecht24 extends Process implements Module {

    /** @var string|null */
    protected $previewType = null;
    /** @var string|null */
    protected $previewHtml = null;
    /** @var string|null */
    protected $testPingMessage = null;


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
        
        $out .= '<div class="uk-grid">';
        $out .= '<div class="uk-width-2-3">';
        
        // Show status section
        $out .= $this->renderStatus();
        
        // Show recent legal text pages
        $out .= $this->renderRecentPages();
        
        // Show registration section if needed
        $out .= $this->renderRegistrationSection();
        
        // Show sync form
        $out .= $this->renderSyncForm();

        // Show dry-run preview form
        $out .= $this->renderPreviewForm();

        // Show test webhook (ping) action
        $out .= $this->renderTestPingForm();
        
        $out .= '</div>';
        
        // Sidebar with configuration info
        $out .= $this->renderSidebar();
        
        $out .= '</div>';

        // If there are results to show (test ping or preview), render them below
        if($this->testPingMessage) {
            $out .= '<div class="uk-panel uk-panel-box" style="margin-top:15px">';
            $out .= '<h4>Webhook Ping Ergebnis</h4>';
            $out .= '<pre style="white-space:pre-wrap">' . $this->sanitizer->entities($this->testPingMessage) . '</pre>';
            $out .= '</div>';
        }

        if($this->previewHtml) {
            $out .= '<div class="uk-panel uk-panel-box" style="margin-top:15px">';
            $out .= '<h4>Dry-Run Vorschau: ' . $this->sanitizer->entities($this->previewType) . '</h4>';
            // Basic defense: remove script tags while allowing HTML formatting
            $safeHtml = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $this->previewHtml);
            $out .= '<div class="pw-content" style="max-height:500px; overflow:auto; border:1px solid #eee; padding:10px">' . $safeHtml . '</div>';
            $out .= '</div>';
        }
        
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
        
        if($action === 'reset_registration') {
            return $this->resetRegistration();
        }

        if($action === 'test_ping') {
            $this->performTestPing();
            return $this->renderPage();
        }

        if($action === 'preview_legal_text') {
            $this->performDryRunPreview();
            return $this->renderPage();
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
     * Reset API client registration
     */
    protected function resetRegistration() {
        $erecht24 = $this->wire('modules')->get('Erecht24');
        
        try {
            // Clear the client_id setting
            $erecht24->deleteModuleSetting('client_id');
            $this->message('API-Client Registrierung wurde erfolgreich zurückgesetzt. Sie können sich nun erneut registrieren.');
            
        } catch(\Exception $e) {
            $this->error('Fehler beim Zurücksetzen der Registrierung: ' . $e->getMessage());
        }
        
        return $this->renderPage();
    }

    
    /**
     * Render status section
     */
    protected function renderStatus() {
        $erecht24 = $this->wire('modules')->get('Erecht24');
        $apiKey = $erecht24->getModuleSetting('api_key');
        $webhookSecret = $erecht24->getModuleSetting('webhook_secret');
        $clientId = $erecht24->getModuleSetting('client_id');
        $isRegistered = !empty($clientId) && $clientId !== 'Not registered yet';
        $lastWebhookStatus = $erecht24->getModuleSetting('last_webhook_status');
        $lastWebhookTime = (int) ($erecht24->getModuleSetting('last_webhook_time') ?: 0);
        
        $out = '<h3>Status</h3>';
        $out .= '<div class="uk-panel uk-panel-box">';
        
        // API Key status
        $out .= '<p><strong>API Key:</strong> ';
        $out .= $apiKey ? '<span style="color: green;">✓ Konfiguriert</span>' : '<span style="color: red;">❌ Nicht konfiguriert</span>';
        $out .= '</p>';
        
        // Webhook Secret status
        $out .= '<p><strong>Webhook Secret:</strong> ';
        $out .= $webhookSecret ? '<span style="color: green;">✓ Konfiguriert</span>' : '<span style="color: red;">❌ Nicht konfiguriert</span>';
        $out .= '</p>';
        
        // Client registration status
        $out .= '<p><strong>API Client:</strong> ';
        $out .= $isRegistered ? '<span style="color: green;">✓ Registriert (' . $clientId . ')</span>' : '<span style="color: orange;">⚠ Nicht registriert</span>';
        $out .= '</p>';

        // Last webhook status/time
        if($lastWebhookTime) {
            $statusColor = ($lastWebhookStatus === 'success') ? 'green' : 'red';
            $statusLabel = $lastWebhookStatus ? $lastWebhookStatus : 'unbekannt';
            $out .= '<p><strong>Letzter Webhook:</strong> ';
            $out .= '<span style="color:' . $statusColor . '">' . $this->sanitizer->entities($statusLabel) . '</span>';
            $out .= ' (' . date('d.m.Y H:i:s', $lastWebhookTime) . ')';
            $out .= '</p>';
        }

        // Last sync times per type
        $types = ['imprint' => 'Impressum', 'privacyPolicy' => 'Datenschutzerklärung', 'privacyPolicySocialMedia' => 'Datenschutzerklärung Social Media'];
        $out .= '<div style="margin-top:10px">';
        $out .= '<strong>Letzte Synchronisation:</strong>';
        $out .= '<ul style="margin:5px 0 0 18px">';
        foreach($types as $key => $label) {
            $ts = (int) ($erecht24->getModuleSetting('last_sync_' . $key) ?: 0);
            $out .= '<li>' . $this->sanitizer->entities($label) . ': ' . ($ts ? date('d.m.Y H:i:s', $ts) : '-') . '</li>';
        }
        $out .= '</ul>';
        $out .= '</div>';
        
        $out .= '</div>';
        
        return $out;
    }
    
    /**
     * Render recent legal text pages
     */
    protected function renderRecentPages() {
        $pages = $this->wire('pages');
        
        $out = '<h3>Aktuelle Rechtstexte</h3>';
        $legalPages = $pages->find("template=legal-text, sort=-created, limit=10, include=unpublished");
        
        if($legalPages->count()) {
            $out .= '<table class="AdminDataTable">';
            $out .= '<thead><tr><th align="left">Titel</th><th align="left">Datum</th><th align="left">Aktionen</th></tr></thead>';
            $out .= '<tbody>';
            
            foreach($legalPages as $legalPage) {
                $out .= '<tr>';
                $out .= '<td><a href="' . $legalPage->editURL . '">' . $legalPage->title . '</a></td>';
                $out .= '<td>' . ($legalPage->legal_date ? date('d.m.Y H:i:s', $legalPage->legal_date) : '-') . '</td>';
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
        
        return $out;
    }
    
    /**
     * Render registration section using InputfieldForm
     */
    protected function renderRegistrationSection() {
        $erecht24 = $this->wire('modules')->get('Erecht24');
        $clientId = $erecht24->getModuleSetting('client_id');
        $isRegistered = !empty($clientId) && $clientId !== 'Not registered yet';
        
        if($isRegistered) {
            return ''; // Already registered
        }
        
        $out = '<h3>API Client Registration</h3>';
        $out .= '<div class="NoticeWarning">';
        $out .= '<p>Ihr ProcessWire-System ist noch nicht als API-Client bei eRecht24 registriert.</p>';
        
        // Create registration form using InputfieldForm
        $form = $this->modules->get('InputfieldForm');
        $form->action = './';
        $form->method = 'post';

        // CSRF token
        $tokenField = $this->modules->get('InputfieldMarkup');
        $tokenField->value = $this->session->CSRF->renderInput();
        $form->add($tokenField);

        $field = $this->modules->get('InputfieldHidden');
        $field->name = 'action';
        $field->value = 'register_client';
        $form->add($field);
        
        $field = $this->modules->get('InputfieldSubmit');
        $field->name = 'submit';
        $field->value = 'Bei eRecht24 registrieren';
        $field->addClass('ui-button-primary');
        $form->add($field);
        
        $out .= $form->render();
        $out .= '</div>';
        
        return $out;
    }
    
    /**
     * Render sync form using InputfieldForm
     */
    protected function renderSyncForm() {
        $out = '<h3>Manuelle Synchronisation</h3>';
        $out .= '<p>Sie können die Rechtstexte manuell von eRecht24 abrufen:</p>';
        
        // Create sync form using InputfieldForm
        $form = $this->modules->get('InputfieldForm');
        $form->action = './';
        $form->method = 'post';

        // CSRF token
        $tokenField = $this->modules->get('InputfieldMarkup');
        $tokenField->value = $this->session->CSRF->renderInput();
        $form->add($tokenField);

        $field = $this->modules->get('InputfieldHidden');
        $field->name = 'action';
        $field->value = 'sync_legal_text';
        $form->add($field);
        
        $field = $this->modules->get('InputfieldSelect');
        $field->name = 'sync_type';
        $field->label = 'Typ auswählen';
        $field->required = true;
        $field->addOption('', 'Typ auswählen...');
        $field->addOption('imprint', 'Impressum');
        $field->addOption('privacyPolicy', 'Datenschutzerklärung');
        $field->addOption('privacyPolicySocialMedia', 'Datenschutzerklärung Social Media');
        $field->addOption('all', 'Alle Rechtstexte');
        $form->add($field);
        
        $field = $this->modules->get('InputfieldSubmit');
        $field->name = 'submit';
        $field->value = 'Synchronisieren';
        $field->addClass('ui-button-primary');
        $form->add($field);
        
        $out .= $form->render();
        
        return $out;
    }

    /**
     * Render dry-run preview form
     */
    protected function renderPreviewForm() {
        $out = '<h3>Vorschau (Dry-Run)</h3>';
        $out .= '<p>Zeigt den Rechtstext aus der API an, ohne Änderungen zu speichern.</p>';
        
        /** @var \ProcessWire\InputfieldForm $form */
        $form = $this->modules->get('InputfieldForm');
        $form->action = './';
        $form->method = 'post';

        /** @var \ProcessWire\InputfieldHidden $field */
        $field = $this->modules->get('InputfieldHidden');
        $field->name = 'action';
        $field->value = 'preview_legal_text';
        $form->add($field);

        /** @var \ProcessWire\InputfieldSelect $field */
        $field = $this->modules->get('InputfieldSelect');
        $field->name = 'preview_type';
        $field->label = 'Typ auswählen';
        $field->required = true;
        $field->addOption('', 'Typ auswählen...');
        $field->addOption('imprint', 'Impressum');
        $field->addOption('privacyPolicy', 'Datenschutzerklärung');
        $field->addOption('privacyPolicySocialMedia', 'Datenschutzerklärung Social Media');
        $form->add($field);

        /** @var \ProcessWire\InputfieldSubmit $field */
        $field = $this->modules->get('InputfieldSubmit');
        $field->name = 'submit';
        $field->value = 'Vorschau anzeigen';
        $form->add($field);

        return $out . $form->render();
    }

    /**
     * Render test webhook (ping) form
     */
    protected function renderTestPingForm() {
        $out = '<h3>Webhook testen</h3>';
        $out .= '<p>Sendet einen Ping an den Webhook-Endpunkt.</p>';

        /** @var \ProcessWire\InputfieldForm $form */
        $form = $this->modules->get('InputfieldForm');
        $form->action = './';
        $form->method = 'post';

        /** @var \ProcessWire\InputfieldHidden $field */
        $field = $this->modules->get('InputfieldHidden');
        $field->name = 'action';
        $field->value = 'test_ping';
        $form->add($field);

        /** @var \ProcessWire\InputfieldSubmit $field */
        $field = $this->modules->get('InputfieldSubmit');
        $field->name = 'submit';
        $field->value = 'Webhook Ping ausführen';
        $form->add($field);

        return $out . $form->render();
    }

    /**
     * Perform test ping by calling the public webhook URL with type=ping
     */
    protected function performTestPing() {
        $erecht24 = $this->wire('modules')->get('Erecht24');
        $secret = $erecht24->getModuleSetting('webhook_secret');
        if(!$secret) {
            $this->error('Webhook Secret ist nicht konfiguriert.');
            return;
        }
        $cfg = $this->wire('config');
        $base = ($cfg->https ? 'https://' : 'http://') . $cfg->httpHost . $cfg->urls->root;
        $url = $base . 'erecht24-webhook/?erecht24_type=ping&erecht24_secret=' . urlencode($secret);
        $http = new \ProcessWire\WireHttp();
        $http->setTimeout(5);
        $response = $http->get($url);
        // ProcessWire WireHttp exposes getHttpCode() for status
        $code = method_exists($http, 'getHttpCode') ? (int) $http->getHttpCode() : 0;
        $body = is_string($response) ? $response : '';
        if(!$code && !$body) {
            // Provide error context if available
            $err = method_exists($http, 'getError') ? $http->getError() : null;
            $this->testPingMessage = 'HTTP 0\n' . ($err ? print_r($err, true) : 'No response');
            $this->error('Webhook Ping fehlgeschlagen.');
            return;
        }
        $this->testPingMessage = 'HTTP ' . $code . "\n" . $body;
        if($code === 200) $this->message('Webhook Ping erfolgreich.'); else $this->error('Webhook Ping fehlgeschlagen.');
    }

    /**
     * Perform dry-run preview by fetching content via module helper
     */
    protected function performDryRunPreview() {
        $input = $this->wire('input');
        $type = $input->post('preview_type');
        if(!$type) {
            $this->error('Bitte wählen Sie einen Typ für die Vorschau.');
            return;
        }
        $erecht24 = $this->wire('modules')->get('Erecht24');
        try {
            $data = $erecht24->getLegalTextPreview($type);
            if(!$data) {
                $this->error('Keine Daten für die Vorschau erhalten.');
                return;
            }
            $this->previewType = $type;
            $this->previewHtml = isset($data['html_de']) ? (string) $data['html_de'] : '';
            if(!$this->previewHtml) {
                $this->error('Die API-Antwort enthielt keinen HTML-Inhalt.');
            } else {
                $this->message('Vorschau erfolgreich geladen.');
            }
        } catch(\Exception $e) {
            $this->error('Fehler bei der Vorschau: ' . $e->getMessage());
        }
    }
    
    /**
     * Render sidebar with configuration info
     */
    protected function renderSidebar() {
        $erecht24 = $this->wire('modules')->get('Erecht24');
        $apiKey = $erecht24->getModuleSetting('api_key');
        $webhookSecret = $erecht24->getModuleSetting('webhook_secret');
        
        $out = '<div class="uk-width-1-3">';
        $out .= '<h3>Konfiguration</h3>';
        
        $out .= '<div class="uk-panel uk-panel-box">';
        $out .= '<h4>API-Konfiguration</h4>';
        $out .= '<p><strong>API Key:</strong> ' . ($apiKey ? '••••••••' : 'Nicht konfiguriert') . '</p>';
        $out .= '<p><strong>Webhook Secret:</strong> ' . ($webhookSecret ? '••••••••' : 'Nicht konfiguriert') . '</p>';
        $out .= '<p><a href="' . $this->config->urls->admin . 'module/edit?name=Erecht24" class="ui-button">Einstellungen bearbeiten</a></p>';
        
        // Add reset registration button
        $clientId = $erecht24->getModuleSetting('client_id');
        $isRegistered = !empty($clientId) && $clientId !== 'Not registered yet';
        
        if($isRegistered) {
            $out .= '<hr style="margin: 15px 0;">';
            $out .= '<p><strong>Client ID:</strong> ' . $clientId . '</p>';
            $out .= '<form method="post" action="./" onsubmit="return confirm(\'Sind Sie sicher, dass Sie die API-Client Registrierung zurücksetzen möchten? Sie müssen sich anschließend erneut bei eRecht24 registrieren.\');">';
            $out .= $this->session->CSRF->renderInput();
            $out .= '<input type="hidden" name="action" value="reset_registration">';
            $out .= '<input type="submit" class="ui-button" style="background-color: #dc3545; color: white;" value="⚠ Registrierung zurücksetzen">';
            $out .= '</form>';
        }
        
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
        
        return $out;
    }

}