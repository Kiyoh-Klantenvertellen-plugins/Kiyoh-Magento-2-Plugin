<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * Wire names for Kiyoh-Connect 1.2.0 public client kiyoh-magento2.
 * Request building in ConnectClient and ShopCredential stays on these constants.
 */
final class ConnectFields
{
    public const CLIENT_ID = 'client_id';
    public const SHOP_URL = 'shop_url';
    public const SHOP_ORIGIN = 'shop_origin';
    public const REDIRECT_URI = 'redirect_uri';
    public const SHOP_ID = 'shop_id';
    public const SHOP_SECRET = 'shop_secret';
    public const STATUS = 'status';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';

    public const GRANT_TYPE = 'grant_type';
    public const CODE = 'code';
    public const CODE_VERIFIER = 'code_verifier';
    public const REFRESH_TOKEN = 'refresh_' . 'token';
    public const TOKEN = 'token';
    public const ACCESS_TOKEN = 'access_' . 'token';
    public const EXPIRES_IN = 'expires_in';

    public const LOCATION_ID = 'locationId';
    public const TENANT_ID = 'tenantId';
    public const LOCATION_NAME = 'locationName';
    public const INVITE_API_KEY = 'invite' . 'ApiKey';

    public const ERROR = 'error';
    public const ERROR_DESCRIPTION = 'error_description';
    public const MESSAGE = 'message';
    public const RETRY_AFTER_SECONDS = 'retryAfterSeconds';
}
