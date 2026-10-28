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

`composer require kiyoh/reviews` installs the newest version Packagist has indexed. Packagist takes the number from Git tags and serves **v1.5.1** ([kiyoh/reviews](https://packagist.org/packages/kiyoh/reviews)). The release zip is on [GitHub Releases](https://github.com/Kiyoh-Klantenvertellen-plugins/Kiyoh-Magento-2-Plugin/releases/latest).

**Versioning**: Composer/Packagist releases follow Git tags. There is no `version` field in `composer.json`.  
**Compatibility**: Magento 2.4.6+ and PHP 8.2+  
**Developer**: Kiyoh  
**License**: Proprietary  
**Module version on this branch**: 1.5.1  
**Newest Git tag**: v1.5.1 (also served by Packagist).  
**Last Updated**: October 2026

---

*This extension is part of your Kiyoh/Klantenvertellen subscription. For questions about your subscription, pricing, or additional features, contact your account manager.*
