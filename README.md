# eRecht24 ProcessWire Module

This module integrates ProcessWire with the eRecht24 API to automatically synchronize legal texts (imprint, privacy policy, social media privacy policy) and create pages with the legal content.

## Features

- **Automatic synchronization** via webhooks from eRecht24
- **Manual synchronization** through admin interface
- **Page creation** for each legal text with date and type
- **Multi-language support** (German and English legal texts)
- **Secure webhook authentication** with secret tokens
- **Admin interface** for management and monitoring
- **Configuration override** via `site/config.php`

## Installation

1.  Copy the `Erecht24` folder to `/site/modules/`
2.  Install the module through ProcessWire admin (Modules > Install)
3.  Create the required template and fields (see Template Setup below)
4.  Configure the module with your eRecht24 API key

## Template Setup

### 1. Create Template

Create a new template called `legal-text` in Admin > Setup > Templates.

### 2. Create Fields

Create the following fields and add them to the `legal-text` template:

-   **legal\_content\_de** (Type: Textarea)
    -   Description: German legal text content
    -   Input Tab: Content
-   **legal\_content\_en** (Type: Textarea)
    -   Description: English legal text content
    -   Input Tab: Content
-   **legal\_type** (Type: Text)
    -   Description: Type of legal text (imprint, privacyPolicy, privacyPolicySocialMedia)
    -   Input Tab: Settings
-   **legal\_date** (Type: Text or Date)
    -   Description: Date when the legal text was created/updated
    -   Input Tab: Settings

### 3. Template File

The template file `/site/templates/legal-text.php` is not automatically created. You need to create it manually. Here is a basic example:

```php
<?php namespace ProcessWire;

// /site/templates/legal-text.php

/** @var Page $page */

?>
<div id="content">
    <h1><?= $page->title ?></h1>
    <div>
        <?= $page->legal_content_de ?>
    </div>
</div>
```

## Configuration

1.  Go to **Admin > Modules > Site > eRecht24 Legal Texts > Configure**
2.  Enter your **eRecht24 Premium API key**
3.  The **webhook secret** is auto-generated for security
4.  Select the **parent page** for the legal text pages.
5.  Copy the **webhook URL** for use in eRecht24 project settings

You can also override the configuration in your `site/config.php` file:

```php
$config->erecht24_api_key = 'YOUR_API_KEY';
$config->erecht24_webhook_secret = 'YOUR_WEBHOOK_SECRET';
```

## eRecht24 Setup

### Step 1: Get Your API Key

1.  Log in to your **eRecht24 Premium account** at https://www.e-recht24.de/
2.  Navigate to **Project Manager**: https://www.e-recht24.de/mitglieder/tools/projekt-manager/
3.  Select or create the project you want to connect
4.  Click the **gear/settings icon** next to your project
5.  Look for the API section
6.  If no API key exists, click **"Neuen API-Schlüssel erzeugen"**
7.  Copy the generated API key

### Step 2: Configure ProcessWire Module

1.  Go to **Admin > Modules > Site > eRecht24 Legal Texts > Configure**
2.  Enter your **eRecht24 API key**
3.  The **webhook secret** is auto-generated
4.  Save the configuration

### Step 3: Register API Client

1.  Go to **Admin > Setup > eRecht24 Legal Texts**
2.  Click **"Bei eRecht24 registrieren"** to automatically register your ProcessWire installation
3.  The module will register itself as an API client with eRecht24
4.  Upon success, you'll see a confirmation message with your Client ID

### Step 4: Verify Setup

After registration, eRecht24 will automatically know about your ProcessWire installation and can send webhook notifications when legal texts are updated.

## Usage

### Automatic Synchronization

Once configured, legal texts will automatically sync when:

-   You click "synchronize" in the eRecht24 Project Manager
-   Legal texts are updated in your eRecht24 account

### Manual Synchronization

You can manually sync legal texts through:

-   **Admin > Setup > eRecht24 Legal Texts**
-   Select the type of legal text to sync
-   Click "Synchronisieren"

### Page Creation

Each time a legal text is synchronized, a new page is created with:

-   **Name**: `YYYY-MM-DD-HH-II-SS-legal-text-type` (e.g., `2024-08-04-12-30-00-impressum`)
-   **Parent**: The page you selected in the module configuration.
-   **Template**: legal-text
-   **Content**: German and/or English legal text

## Security

-   Webhook requests are authenticated using a secret token
-   The webhook endpoint is hidden from public access
-   API credentials are stored securely in ProcessWire configuration

## Troubleshooting

### Admin page not appearing

If you don't see "eRecht24 Legal Texts" under Setup:

1.  **Refresh modules**: Go to **Modules > Refresh** to detect new modules
2.  **Manual installation**: Go to **Modules > Install** and look for "eRecht24 Admin" - install it manually
3.  **Check permissions**: Ensure your user has the "erecht24-admin" permission

### Template not found error

Make sure you've created the `legal-text` template with the required fields.

### Webhook not working

1.  Check that the webhook URL is correctly configured in eRecht24
2.  Verify the webhook secret matches in both systems
3.  Check ProcessWire logs for error messages (`/site/assets/logs/erecht24.txt`)

### API errors

1.  Verify your eRecht24 API key is correct
2.  Ensure your eRecht24 Premium account is active
3.  Check that legal texts are configured in your eRecht24 project

### Resetting Client Registration

If you need to re-register the client with eRecht24, you can reset the registration in the admin interface:

1.  Go to **Admin > Setup > eRecht24 Legal Texts**
2.  In the sidebar, click on the **"⚠ Registrierung zurücksetzen"** button.

## Requirements

-   ProcessWire 3.0+
-   eRecht24 Premium account with API access
-   PHP cURL extension (for API requests)

## License

This module is provided as-is for integration with eRecht24 services.
