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
- **Magento**: 2.3.0 or higher
- **PHP**: 7.2 or higher
- **Server Extensions**: cURL, JSON (usually pre-installed)

### Recommended for Best Performance
- **Magento**: 2.4.6 or higher (LTS version)
- **PHP**: 8.1 or higher
- **SSL Certificate**: For secure API communication

### Compatibility

The extension is compatible with:
- ✅ Magento Open Source 2.3.x and 2.4.x
- ✅ Adobe Commerce (Magento Commerce) 2.3.x and 2.4.x
- ✅ All standard Magento installations
- ✅ Multi-store and multi-website setups
- ✅ Cloud hosting and on-premise installations

**Note for older Magento installations:**
- Magento 2.3.x is supported but end-of-life (no security updates)
- We recommend upgrading to Magento 2.4.x for security and performance
- If you're on Magento 2.2.x or older, contact your technical team about upgrading

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
If you're running an older version of Magento (2.3.x), ensure your PHP version is at least 7.2. Your technical team can verify this and upgrade if needed.

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
- Click **Save Config**

The system will automatically verify your credentials when you save.

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

## About This Extension

**Versioning**: Composer/Packagist releases follow Git tags  
**Compatibility**: Magento 2.3.0+ and PHP 7.2+  
**Developer**: Kiyoh  
**License**: Proprietary  
**Latest release**: v1.2.0  
**Last Updated**: September 2026

---

*This extension is part of your Kiyoh/Klantenvertellen subscription. For questions about your subscription, pricing, or additional features, contact your account manager.*
