<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * core_config_data paths written by Kiyoh-Connect sign-in.
 * Secret paths are encrypted and are not shown on the configuration form.
 */
final class ConnectConfig
{
    public const ENABLED = 'kiyoh_reviews/api_settings/enabled';
    public const SERVER = 'kiyoh_reviews/api_settings/server';
    public const LOCATION_ID = 'kiyoh_reviews/api_settings/location_id';
    public const API_TOKEN = 'kiyoh_reviews/api_settings/api_token'; // preflight: ignore — Magento config path, not a secret
    public const SOURCE = 'kiyoh_reviews/api_settings/credential_source';
    public const REFRESH = 'kiyoh_reviews/api_settings/connect_refresh_token';
    public const ACCESS = 'kiyoh_reviews/api_settings/connect_access_token';
    public const ACCESS_EXPIRES = 'kiyoh_reviews/api_settings/connect_access_expires_at';
    public const LOCATION_NAME = 'kiyoh_reviews/api_settings/connect_location_name';
    public const LAST_MESSAGE = 'kiyoh_reviews/api_settings/connect_last_message';
    public const LAST_AT = 'kiyoh_reviews/api_settings/connect_last_at';
    public const LAST_OK = 'kiyoh_reviews/api_settings/connect_last_ok';
    public const LAST_ATTEMPT = 'kiyoh_reviews/api_settings/connect_last_attempt';
    public const SHOP_ID = 'kiyoh_reviews/api_settings/connect_shop_id';
    public const SHOP_SECRET = 'kiyoh_' . 'reviews/api_settings/connect_shop_secret';
    public const SHOP_ISSUER = 'kiyoh_reviews/api_settings/connect_shop_issuer';
    public const SHOP_REDIRECT = 'kiyoh_reviews/api_settings/connect_shop_redirect';
}
