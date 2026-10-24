# Kiyoh Reviews - User Guide

**Collect more reviews and boost your store's credibility with automated review requests**

## What is Kiyoh Reviews?

Kiyoh Reviews is a Magento 2 extension that automatically collects customer reviews for your store and products. It integrates seamlessly with Kiyoh and Klantenvertellen review platforms, helping you build trust and increase conversions.

## Key Benefits

✅ **Automated Review Collection**: Automatically send review requests after orders are completed  
✅ **Product Reviews**: Collect reviews for specific products customers purchased  
✅ **Perfect Timing**: Send review requests at the optimal time (e.g., 7 days after delivery)  
✅ **Multi-Language**: Automatically detects customer language for personalized emails  
✅ **Easy Setup**: Configure once and let it run automatically  
✅ **Boost SEO**: Product reviews improve search engine rankings  
✅ **Increase Trust**: Display ratings and reviews to build customer confidence  

## System Requirements

### Minimum Requirements
- **Magento**: 2.4.6 or higher (first release that officially supports PHP 8.2)
- **PHP**: 8.2 or higher
- **Server Extensions**: cURL, JSON (usually pre-installed)

### Recommended for Best Performance
- **Magento**: 2.4.9 or higher
- **PHP**: 8.4 or higher
- **SSL Certificate**: For secure API communication

### Compatibility

The extension is compatible with:
- ✅ Magento Open Source 2.4.6 and newer
- ✅ Adobe Commerce (Magento Commerce) 2.4.6 and newer
- ✅ All standard Magento installations on PHP 8.2+
- ✅ Multi-store and multi-website setups
- ✅ Cloud hosting and on-premise installations

**Note for older installations:**
- PHP 8.1 and older are not supported. Those versions are end-of-life and are affected by PHP CGI argument-injection issues (php2shell).
- Magento 2.3.x and Magento 2.4.0–2.4.5 do not support PHP 8.2, so they cannot run this release.
- Upgrade Magento to 2.4.6 or newer and PHP to 8.2 or newer before installing.

## Getting Started

### Step 1: Get Your API Credentials

Contact your Kiyoh or Klantenvertellen account manager to receive:
- Your Location ID
- Your API Token

Keep these credentials handy for the next step.

### Step 2: Install the Extension

Your developer or technical team will install the extension. The installation process typically takes 5-10 minutes.

**Composer installation:**
1. Run `composer require kiyoh/reviews`
2. Run `php bin/magento setup:upgrade`
3. If the store is in production mode, run `php bin/magento setup:di:compile`
4. Clear cache with `php bin/magento cache:flush`

Once installed, you'll see a new "Kiyoh" section in your Magento admin panel under **Stores > Configuration**.

**Compatibility Check:**
This release requires PHP 8.2 or newer and Magento 2.4.6 or newer. Your technical team can verify both versions before installing.

### Step 3: Configure the Extension

1. Log into your Magento admin panel
2. Go to **Stores > Configuration**
3. Find **Kiyoh** in the left menu
4. Click **Reviews Configuration**

#### Enter Your API Credentials

In the **API Settings** section:
- **Enable Kiyoh Reviews**: Set to **Yes**
- **Server**: Select your platform (e.g., klantenvertellen.nl)
- **Location ID**: Enter your Location ID
- **API Token**: Enter your API Token
- **Kiyoh Mobile URL(s)**: Leave the default `https://kiyoh-mobile.com`, or enter a comma-separated list when this store view should also report to another Kiyoh Mobile host (for example `https://kiyohmobileui-staging.up.railway.app`)
- Click **Save Config**

#### Connect with Kiyoh / Klantenvertellen

On the same API Settings group, **Connect with Kiyoh / Klantenvertellen** signs the merchant in through Kiyoh-Connect instead of pasting a token. Pick the Server (`kiyoh.com` uses `https://connect.kiyoh.com`, `klantenvertellen.nl` uses `https://connect.klantenvertellen.nl`), save if you changed it, then click Connect. A window opens for the Kiyoh or Klantenvertellen login. After the merchant picks a location, this shop's Location ID and API token are filled in for the current scope.

**Test connection** checks the saved Location ID and API token with one statistics call and shows a short success or failure message.

**Disconnect** revokes the Kiyoh-Connect session. If the credentials came from Connect, the Location ID and API token are cleared. A token that was pasted by hand is left in place.

The manual Location ID and API Token fields are unchanged and remain the fallback for existing installs. Pasting a token does not remove the shop credential Connect already issued.

No shared HMAC key or client secret is configured on the shop. The module registers itself with Kiyoh-Connect, stores the one-time shop credential encrypted, and sends that credential on token, refresh, and revoke calls.

The browser returns to `https://{shop-host}/kiyoh/oauth/callback` (the shop host, not the Magento admin path and not a store-code prefix). The vhost must route that path to Magento. `Add Store Code to Urls` is not part of that return address.

The system will automatically verify your credentials when you save. Saving also sends this store view's integration health to each Kiyoh Mobile URL.

#### Configure Review Invitations

In the **Review Invitations** section:

**Basic Settings:**
- **Enable Review Invitations**: Set to **Yes**
- **Invitation Type**: Choose what to request
  - **Shop + Product Reviews**: Ask for both store and product reviews (recommended)
  - **Product Reviews Only**: Only ask for product reviews
  - **Shop Reviews Only**: Only ask for store reviews (no product-specific reviews)
- **Order Status Trigger**: Select when to send invitations (usually "Complete")
- **Delay (Days)**: How many days to wait before sending (recommended: 7 days)

**Advanced Settings:**
- **Maximum Products Per Invitation**: How many products to include (recommended: 3-5)
  - Too many products can overwhelm customers
  - Focus on the most important items
- **Product Sort Order**: Choose how to prioritize products when multiple items are in an order
  - **Cart Order**: Use the order items appear in the cart (default)
  - **Price (High to Low)**: Prioritize expensive items first
  - **Price (Low to High)**: Prioritize cheaper items first
  - **Name (A to Z)**: Sort products alphabetically
  - **Name (Z to A)**: Sort products reverse alphabetically
  - **SKU (A to Z)**: Sort by product code alphabetically
  - **SKU (Z to A)**: Sort by product code reverse alphabetically
- **Fallback Language**: Default language if customer language can't be detected (e.g., "en" for English)

**Choosing the Right Invitation Type:**
- **Shop + Product Reviews**: Best for most stores - gives comprehensive feedback
- **Product Reviews Only**: Good for stores focused on product quality and SEO
- **Shop Reviews Only**: Ideal for service-based businesses or when you want to focus on overall customer experience

**Optional Filters:**
- **Exclude Customer Groups**: Skip certain customer groups (e.g., wholesale customers)
- **Exclude Product Attribute Sets**: Skip certain product types (e.g., gift cards)

Click **Save Config** when done.

#### Enable Product Synchronization

In the **Product Synchronization** section:

- **Enable Product Sync**: Set to **Yes**
- **Auto Sync on Product Changes**: Set to **Yes** (recommended)
  - This automatically updates product information when you edit products
- **Excluded Product Types**: Select product types you don't want to sync (optional)
  - Example: Virtual products, downloadable products
  - Configurable parents are always excluded; invitations use the purchased child/variant SKU
- **Excluded Product Codes**: Enter specific SKUs to exclude (optional)
  - Example: SAMPLE-001, TEST-SKU

**Initial Product Sync:**
- Important: first input your API key and Location ID and click save.
- Switch the scope dropdown to each **store view** that should sync, save credentials, then run **Bulk Product Sync** for that store
- Do not leave the page while the sync is in progress
- This may take a few minutes depending on your catalog size
- You only need to do this once per store view (or let nightly cron finish the first sync and mark it done)

Click **Save Config** when done.

## Multi-store / multi-location

Configure **each store view** separately (Stores → Configuration → store-view switcher → Kiyoh):

1. Enable Kiyoh Reviews
2. Set Server, Location ID, and API Token for that store’s Kiyoh/Klantenvertellen location
3. Enable Review Invitations and Product Sync as needed
4. Save, then run Bulk Product Sync for that store view

Invitations always use the order’s `store_id` to pick credentials, so eurobedden.nl and bed4you.nl (or any two storefronts) can each send to their own location.

### Kiyoh Mobile health

Each store view that has a Location ID and API token reports its own shop to Kiyoh Mobile. The shop id is the store's secure base host. A store view other than the default view also includes its store code, so two views on one domain stay separate.

Health is sent when you save the Kiyoh configuration, when an invitation is sent or fails, when products are synced, and once a day by cron (`kiyoh_reviews_mobile_health`). The same payload is sent again after 24 hours. Each call uses a short timeout. A slow or unavailable health endpoint leaves orders and invitations running.

The report includes connection status, invitation results from the last 24 hours, and up to 500 synced product codes. This module records no incoming webhooks and does not inject a review widget, and the health payload says so.

### Product codes (SKU rules)

- Kiyoh `product_code` is the Magento **SKU** (sanitized for API safety).
- **Configurable products:** invitations and order-time sync use the purchased **child/variant** SKU, not the parent. Parents are not bulk/auto-synced.
- **Custom options** that append option SKUs onto the order line SKU: the plugin sends the **catalog/simple SKU** so invites match catalog sync.
- Optional fields try common attributes: GTIN (`gtin`, `ean`, `ean13`, `upc`), MPN (`mpn`), brand (`brand`, `manufacturer`). Empty values are omitted.

### Storefront / Hyvä display

This module focuses on invitations and product catalog sync. Shop badges and product-page review UI are **out of scope** here — use a Kiyoh embed/widget or your theme (including Hyvä) to display reviews. Match reviews on the same SKU + location as configured above.

### Migrating from Interactivated Customerreview (`interactivated/customerreview`)

That package is a different module. Cut over as follows:

1. `composer remove interactivated/customerreview` (or disable `Interactivated_Customerreview`)
2. `composer require kiyoh/reviews`
3. `bin/magento module:enable Kiyoh_Reviews`
4. `bin/magento setup:upgrade`
5. Production: `bin/magento setup:di:compile` and `setup:static-content:deploy` if needed
6. `bin/magento cache:flush`
7. Re-enter Location ID + API token **per store view** under Stores → Configuration → Kiyoh (old `interactivated/*` settings are not migrated)
8. Enable invitations/product sync, run Bulk Product Sync per store

Disable the old module completely so orders do not send duplicate invites.

## How It Works

### Automatic Review Requests

Once configured, the extension works automatically:

1. **Customer Places Order**: A customer completes a purchase
2. **Order is Completed**: You mark the order as "Complete" (or your configured status)
3. **Waiting Period**: The system waits the configured delay (e.g., 7 days)
4. **Review Request Sent**: Customer receives an email from Kiyoh/Klantenvertellen
5. **Customer Leaves Review**: Customer clicks the link and writes a review
6. **Review Published**: Review appears on your Kiyoh profile and can be displayed on your website

### What Customers Receive

Customers receive a personalized email in their language with:
- A friendly greeting using their name
- A link to leave a review
- The products they purchased (with images) - if product reviews are enabled
- A simple rating system

**Note**: If you choose "Shop Reviews Only", customers will only be asked to review your store, not specific products. This is useful if you prefer to focus on overall service quality rather than individual product feedback.

## Managing Reviews

### Where to See Your Reviews

1. **Kiyoh Dashboard**: Log into your Kiyoh/Klantenvertellen account to see all reviews
2. **Email Notifications**: You'll receive email alerts for new reviews
3. **Magento Logs**: Your technical team can check logs for invitation status

### Responding to Reviews

- Log into your Kiyoh/Klantenvertellen dashboard
- Navigate to the reviews section
- Click on a review to respond
- Your response appears publicly below the review

### Handling Negative Reviews

1. **Respond Quickly**: Address concerns within 24-48 hours
2. **Be Professional**: Stay calm and courteous
3. **Offer Solutions**: Provide ways to resolve the issue
4. **Take It Offline**: Invite them to contact you directly
5. **Learn and Improve**: Use feedback to improve your service

## Permissions, stored data, and uninstall

### Permissions

- **Admin ACL** `Kiyoh_Reviews::config` (under Stores → Configuration). Anyone who can edit this configuration can connect, test, disconnect, and read the saved token. No other Magento ACL is requested.
- **Storefront route** `https://{shop-host}/kiyoh/oauth/callback` is public because Kiyoh-Connect redirects the merchant's browser there. It finishes a sign-in only when `state` matches a pending login an admin started in the last 15 minutes. It does not print the API token.
- **Outbound HTTPS**, certificate checks on: `connect.kiyoh.com`, `connect.klantenvertellen.nl`, `www.kiyoh.com`, `www.klantenvertellen.nl`, and each configured Kiyoh Mobile URL.
- **OAuth scope** `integration:connect` only. The module does not ask Kiyoh for any broader scope.

### Data stored

- **Configuration** (`core_config_data`, per default / website / store scope): Server, Location ID, API token (encrypted), credential source (`connect` or `manual`), Kiyoh-Connect refresh token and access token (encrypted), access-token expiry, location name, the last Connect result message, and the one-time shop credential (`shop_id` and `shop_secret`, both encrypted) plus the issuer and return address it was registered for. The shop credential is not shown on the configuration form.
- **`kiyoh_reviews_connect_login`**: one row per in-progress sign-in (encrypted PKCE verifier, return address, scope). The row is deleted when the callback runs or after 15 minutes.
- **`kiyoh_reviews_shop_health`**: one row per store view with the latest health payload state (not a history).

Customer name, email, and order id are sent to Kiyoh when an invitation is sent. They are not copied into these tables. Tokens are not written to the Magento log.

The refresh token is kept until Disconnect, until a manual token replaces it, or until uninstall. The shop credential is kept when a manual token is saved, because Connect returns it only once. Disconnect removes it after the shop registration is revoked. Kiyoh-Connect refresh tokens last 30 days on the Connect side. Access tokens last about 1 hour. Health rows are overwritten in place.

### Uninstall

- `bin/magento module:disable Kiyoh_Reviews` stops the module and **keeps** configuration, the health table, and any Connect session.
- `bin/magento module:uninstall -r Kiyoh_Reviews` drops `kiyoh_reviews_shop_health` and `kiyoh_reviews_connect_login`, deletes `core_config_data` paths under `kiyoh_reviews/`, and best-effort calls Kiyoh-Connect `POST /revoke` and `POST /shops/revoke` with each stored shop credential. A scope with no shop credential is logged and skipped for the remote call. Local data is still removed. Orders, customers, and other modules' tables are not touched.

## About This Extension

**Versioning**: Composer/Packagist releases follow Git tags  
**Compatibility**: Magento 2.4.6+ and PHP 8.2+  
**Developer**: Kiyoh  
**License**: Proprietary  
**Latest release**: v1.5.0  
**Last Updated**: October 2026

---

*This extension is part of your Kiyoh/Klantenvertellen subscription. For questions about your subscription, pricing, or additional features, contact your account manager.*
