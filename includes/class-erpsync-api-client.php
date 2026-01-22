<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class API_Client {

    const OPTION_USERNAME              = 'erp_sync_api_username';
    const OPTION_PASSWORD              = 'erp_sync_api_password';
    const OPTION_WSDL                  = 'erp_sync_api_wsdl';
    const OPTION_FORCE_LOCATION        = 'erp_sync_api_force_location';
    const OPTION_TIMEOUT               = 'erp_sync_api_timeout';
    const OPTION_DEBUG                 = 'erp_sync_api_debug';
    const OPTION_SOAP_VERSION          = 'erp_sync_api_soap_version';

    const OPTION_LAST_RAW_JSON         = 'erp_sync_last_raw_excerpt';
    const OPTION_LAST_RAW_XML          = 'erp_sync_last_raw_xml';
    const OPTION_LAST_PRODUCTS_XML     = 'erp_sync_last_products_xml';
    const OPTION_LAST_STOCK_XML        = 'erp_sync_last_stock_xml';
    const OPTION_LAST_REQUEST_XML      = 'erp_sync_last_request_xml';
    const OPTION_LAST_SOAP_FAULT       = 'erp_sync_last_soap_fault';
    const OPTION_LAST_REQUEST_HEADERS  = 'erp_sync_last_request_headers';
    const OPTION_LAST_RESPONSE_HEADERS = 'erp_sync_last_response_headers';
    const OPTION_LAST_INFOCARDS_META   = 'erp_sync_last_infocards_meta';
    const OPTION_LAST_PRODUCTS_META    = 'erp_sync_last_products_meta';
    const OPTION_LAST_STOCK_META       = 'erp_sync_last_stock_meta';

    private string $wsdl;
    private string $username;
    private string $password;
    private string $force_location;
    private int $timeout;
    private bool $debug;
    private int $soap_version;

    public function __construct() {
        $this->wsdl           = (string) get_option( self::OPTION_WSDL, 'http://92.241.78.182:8080/artsw2022/ws/WebExchange.1cws?wsdl' );
        $this->username       = (string) get_option( self::OPTION_USERNAME, '' );
        
        // Handle password with improved decryption (supports backward compatibility)
        $password_stored = (string) get_option( self::OPTION_PASSWORD, '' );
        $this->password = Security::decrypt( $password_stored );
        
        // If decryption returns empty but we have a stored value, it might be plain text
        if ( empty( $this->password ) && ! empty( $password_stored ) ) {
            $this->password = $password_stored;
            
            // Re-encrypt it for future use
            $encrypted = Security::encrypt( $password_stored );
            if ( ! empty( $encrypted ) ) {
                update_option( self::OPTION_PASSWORD, $encrypted );
                Logger::instance()->log( 'Password re-encrypted from plain text', [] );
            }
        }
        
        $this->force_location = (string) get_option( self::OPTION_FORCE_LOCATION, '' );
        $this->timeout        = (int) get_option( self::OPTION_TIMEOUT, 30 );
        $this->debug          = (bool) get_option( self::OPTION_DEBUG, false );
        $this->soap_version   = (int) get_option( self::OPTION_SOAP_VERSION, 11 );
    }

    /**
     * NON-DESTRUCTIVE: verifies endpoint/auth via Products method
     */
    public function test_connection(): array {
        try {
            $this->fetch_products_raw();
            if ( $this->debug ) {
                Logger::instance()->log( 'Test connection (non-destructive) OK', [] );
            }
            return [ 'success' => true, 'non_destructive' => true ];
        } catch ( \Throwable $e ) {
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    public function fetch_cards(): array {
        return $this->fetch_cards_remote();
    }

    public function fetch_products_raw(): mixed {
        // Apply security checks before API call
        do_action( 'erp_sync_before_api_call' );
        
        $client = $this->build_client();
        $start = microtime(true);

        try {
            $response = $client->__soapCall( 'Products', [] );
            $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );

            if ( $this->debug ) {
                $req = method_exists( $client, '__getLastRequest' ) ? $client->__getLastRequest() : '';
                $res = method_exists( $client, '__getLastResponse' ) ? $client->__getLastResponse() : '';
                $reqHeaders = method_exists( $client, '__getLastRequestHeaders' ) ? $client->__getLastRequestHeaders() : '';
                $resHeaders = method_exists( $client, '__getLastResponseHeaders' ) ? $client->__getLastResponseHeaders() : '';

                update_option( self::OPTION_LAST_REQUEST_XML,      $this->excerpt( $req, 5000 ) );
                update_option( self::OPTION_LAST_REQUEST_HEADERS,  $this->excerpt( $reqHeaders, 4000 ) );
                $excerpt = $this->excerpt( $res, 5000 );
                update_option( self::OPTION_LAST_PRODUCTS_XML,     $excerpt );
                update_option( self::OPTION_LAST_RESPONSE_HEADERS, $this->excerpt( $resHeaders, 4000 ) );

                Logger::instance()->log( 'Products raw stored', [
                    'len'               => strlen( $excerpt ),
                    'duration_ms'       => $duration_ms,
                    'soap_version'      => $this->soap_version,
                    'force_endpoint'    => $this->force_location ? 1 : 0,
                    'request_bytes'     => strlen( (string) $req ),
                    'response_bytes'    => strlen( (string) $res ),
                    'memory_peak_kb'    => (int) ( memory_get_peak_usage(true) / 1024 ),
                ] );
            }

            return $response;
        } catch ( \SoapFault $fault ) {
            $this->capture_fault_artifacts( $client, $fault, 'Products', $start );
            throw $fault;
        } catch ( \Throwable $e ) {
            $this->capture_generic_exception_artifacts( $client, $e, 'Products', $start );
            throw $e;
        }
    }

    /**
     * Fetch and parse products catalog from SOAP endpoint.
     *
     * @return array Array of normalized product data.
     * @throws \SoapFault When SOAP call fails.
     * @throws \Throwable For other exceptions.
     */
    public function fetch_products_catalog(): array {
        // Apply security checks before API call
        do_action( 'erp_sync_before_api_call' );

        $client = $this->build_client();
        $start = microtime(true);

        try {
            $response = $client->__soapCall( 'Products', [] );
            $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
            $products = $this->parse_products_response( $response );
            $row_count = count( $products );

            $this->maybe_store_debug( $client, $response, 'products' );

            if ( $this->debug ) {
                $meta = [
                    'duration_ms'        => $duration_ms,
                    'rows_parsed'        => $row_count,
                    'soap_version'       => $this->soap_version,
                    'force_endpoint'     => $this->force_location ? 1 : 0,
                    'request_bytes'      => $this->safe_len( method_exists($client,'__getLastRequest') ? $client->__getLastRequest() : '' ),
                    'response_bytes'     => $this->safe_len( method_exists($client,'__getLastResponse') ? $client->__getLastResponse() : '' ),
                    'memory_peak_kb'     => (int) ( memory_get_peak_usage(true) / 1024 ),
                    'time_utc'           => gmdate( 'Y-m-d H:i:s' ),
                ];
                update_option( self::OPTION_LAST_PRODUCTS_META, wp_json_encode( $meta ) );
                Logger::instance()->log( 'Products call meta', $meta );
            }

            return $products;
        } catch ( \SoapFault $fault ) {
            $this->capture_fault_artifacts( $client, $fault, 'Products', $start );
            throw $fault;
        } catch ( \Throwable $e ) {
            $this->capture_generic_exception_artifacts( $client, $e, 'Products', $start );
            throw $e;
        }
    }

    /**
     * Fetch product stock and prices from SOAP endpoint.
     *
     * @param string $vendor_codes Comma-separated list of VendorCodes (SKUs). Empty returns all.
     * @return array Array of normalized stock/price data.
     * @throws \SoapFault When SOAP call fails.
     * @throws \Throwable For other exceptions.
     */
    public function fetch_products_stock( string $vendor_codes = '' ): array {
        // Apply security checks before API call
        do_action( 'erp_sync_before_api_call' );

        $client = $this->build_client();
        $start = microtime(true);

        try {
            $response = $client->__soapCall( 'GetProductsStock', [ [ 'VendorCode' => $vendor_codes ] ] );
            $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
            $stock_data = $this->parse_stock_response( $response );
            $row_count = count( $stock_data );

            $this->maybe_store_debug( $client, $response, 'stock' );

            if ( $this->debug ) {
                $req = method_exists( $client, '__getLastRequest' ) ? $client->__getLastRequest() : '';
                $res = method_exists( $client, '__getLastResponse' ) ? $client->__getLastResponse() : '';

                update_option( self::OPTION_LAST_STOCK_XML, $this->excerpt( $res, 5000 ) );

                $meta = [
                    'duration_ms'        => $duration_ms,
                    'rows_parsed'        => $row_count,
                    'vendor_codes_param' => $vendor_codes ? substr( $vendor_codes, 0, 100 ) : '(all)',
                    'soap_version'       => $this->soap_version,
                    'force_endpoint'     => $this->force_location ? 1 : 0,
                    'request_bytes'      => $this->safe_len( $req ),
                    'response_bytes'     => $this->safe_len( $res ),
                    'memory_peak_kb'     => (int) ( memory_get_peak_usage(true) / 1024 ),
                    'time_utc'           => gmdate( 'Y-m-d H:i:s' ),
                ];
                update_option( self::OPTION_LAST_STOCK_META, wp_json_encode( $meta ) );
                Logger::instance()->log( 'GetProductsStock call meta', $meta );
            }

            return $stock_data;
        } catch ( \SoapFault $fault ) {
            $this->capture_fault_artifacts( $client, $fault, 'GetProductsStock', $start );
            throw $fault;
        } catch ( \Throwable $e ) {
            $this->capture_generic_exception_artifacts( $client, $e, 'GetProductsStock', $start );
            throw $e;
        }
    }

    public function fetch_cards_remote(): array {
        // Apply security checks before API call
        do_action( 'erp_sync_before_api_call' );
        
        $client = $this->build_client();
        $start = microtime(true);

        try {
            // Try different parameter formats
            $params_to_try = [
                [],                           // Empty array
                [ new \stdClass() ],          // Empty object
                [ [
                    'DateFrom' => date('Y-m-d', strtotime('-2 years')),
                    'DateTo'   => date('Y-m-d')
                ] ],
                [ [
                    'Filter' => ''
                ] ]
            ];
            
            $response = null;
            $successful_params = null;
            
            foreach ( $params_to_try as $params ) {
                try {
                    $response = $client->__soapCall( 'InformationCards', $params );
                    $successful_params = $params;
                    
                    // Check if we got data
                    $test_arr = json_decode( json_encode( $response ), true );
                    if ( ! empty( $test_arr ) ) {
                        Logger::instance()->log( 'InformationCards successful with params', [
                            'params' => $params
                        ] );
                        break; // Found working params
                    }
                } catch ( \Throwable $e ) {
                    // Try next parameter set
                    continue;
                }
            }
            
            if ( ! $response ) {
                throw new \RuntimeException( 'All parameter attempts failed' );
            }

            $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
            $cards       = $this->parse_information_cards( $response );
            $row_count   = count( $cards );

            $this->maybe_store_debug( $client, $response, 'cards' );

            if ( $this->debug ) {
                $meta = [
                    'duration_ms'        => $duration_ms,
                    'rows_parsed'        => $row_count,
                    'soap_version'       => $this->soap_version,
                    'force_endpoint'     => $this->force_location ? 1 : 0,
                    'successful_params'  => $successful_params,
                    'request_bytes'      => $this->safe_len( method_exists($client,'__getLastRequest') ? $client->__getLastRequest() : '' ),
                    'response_bytes'     => $this->safe_len( method_exists($client,'__getLastResponse') ? $client->__getLastResponse() : '' ),
                    'memory_peak_kb'     => (int) ( memory_get_peak_usage(true) / 1024 ),
                    'time_utc'           => gmdate( 'Y-m-d H:i:s' ),
                ];
                update_option( self::OPTION_LAST_INFOCARDS_META, wp_json_encode( $meta ) );
                Logger::instance()->log( 'InformationCards call meta', $meta );
            }

            return $cards;
        } catch ( \SoapFault $fault ) {
            $this->capture_fault_artifacts( $client, $fault, 'InformationCards', $start );
            throw $fault;
        } catch ( \Throwable $e ) {
            $this->capture_generic_exception_artifacts( $client, $e, 'InformationCards', $start );
            throw $e;
        }
    }

    private function build_client(): \SoapClient {
        if ( empty( $this->username ) || empty( $this->password ) ) {
            throw new \RuntimeException( 'Credentials not set.' );
        }
        
        $options = [
            'trace'              => $this->debug ? 1 : 0,
            'exceptions'         => true,
            'cache_wsdl'         => WSDL_CACHE_MEMORY,
            'login'              => $this->username,
            'password'           => $this->password,
            'connection_timeout' => $this->timeout,
            'soap_version'       => ( $this->soap_version === 12 ) ? SOAP_1_2 : SOAP_1_1,
            'stream_context'     => stream_context_create( [
                'http' => [
                    'timeout' => $this->timeout,
                ],
            ] ),
        ];
        
        if ( $this->force_location ) {
            $options['location'] = $this->force_location;
        }
        
        if ( $this->debug ) {
            Logger::instance()->log( 'SOAP client built', [
                'wsdl'         => $this->wsdl,
                'location'     => $options['location'] ?? '(from WSDL)',
                'timeout'      => $this->timeout,
                'soap_version' => $this->soap_version,
            ] );
        }
        
        return new \SoapClient( $this->wsdl, $options );
    }

    private function maybe_store_debug( \SoapClient $client, mixed $response, string $tag ): void {
        if ( ! $this->debug ) return;
        
        try {
            $rawArr  = json_decode( json_encode( $response ), true );
            $rawJson = wp_json_encode( $rawArr, JSON_UNESCAPED_UNICODE );
            update_option( self::OPTION_LAST_RAW_JSON, $this->excerpt( $rawJson, 1500 ) );

            if ( method_exists( $client, '__getLastResponse' ) ) {
                $xml = $client->__getLastResponse();
                $xmlExcerpt = $this->excerpt( $xml, 5000 );
                update_option( self::OPTION_LAST_RAW_XML, $xmlExcerpt );
            }
            
            if ( method_exists( $client, '__getLastRequest' ) ) {
                update_option( self::OPTION_LAST_REQUEST_XML, $this->excerpt( $client->__getLastRequest(), 5000 ) );
            }
            
            if ( method_exists( $client, '__getLastRequestHeaders' ) ) {
                update_option( self::OPTION_LAST_REQUEST_HEADERS, $this->excerpt( $client->__getLastRequestHeaders(), 4000 ) );
            }
            
            if ( method_exists( $client, '__getLastResponseHeaders' ) ) {
                update_option( self::OPTION_LAST_RESPONSE_HEADERS, $this->excerpt( $client->__getLastResponseHeaders(), 4000 ) );
            }

            Logger::instance()->log( 'Debug stored raw '.$tag, [
                'json_len'       => strlen( (string) $rawJson ),
                'tag'            => $tag,
                'request_hdr_sz' => $this->safe_len( method_exists($client,'__getLastRequestHeaders') ? $client->__getLastRequestHeaders() : '' ),
                'response_hdr_sz'=> $this->safe_len( method_exists($client,'__getLastResponseHeaders') ? $client->__getLastResponseHeaders() : '' ),
            ] );
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Debug raw store failed', [ 'error' => $e->getMessage() ] );
        }
    }

    private function capture_fault_artifacts( \SoapClient $client, \SoapFault $fault, string $op, float $start ): void {
        if ( ! $this->debug ) return;
        
        $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
        $req  = method_exists( $client, '__getLastRequest' ) ? $client->__getLastRequest() : '';
        $res  = method_exists( $client, '__getLastResponse' ) ? $client->__getLastResponse() : '';
        $reqH = method_exists( $client, '__getLastRequestHeaders' ) ? $client->__getLastRequestHeaders() : '';
        $resH = method_exists( $client, '__getLastResponseHeaders' ) ? $client->__getLastResponseHeaders() : '';

        update_option( self::OPTION_LAST_REQUEST_XML,      $this->excerpt( $req, 5000 ) );
        update_option( self::OPTION_LAST_RAW_XML,          $this->excerpt( $res, 5000 ) );
        update_option( self::OPTION_LAST_SOAP_FAULT,       sprintf( '%s: %s', $fault->faultcode ?? 'SOAP-FAULT', $fault->getMessage() ) );
        update_option( self::OPTION_LAST_REQUEST_HEADERS,  $this->excerpt( $reqH, 4000 ) );
        update_option( self::OPTION_LAST_RESPONSE_HEADERS, $this->excerpt( $resH, 4000 ) );

        $meta = [
            'op'                => $op,
            'faultcode'         => $fault->faultcode ?? '',
            'message'           => $fault->getMessage(),
            'duration_ms'       => $duration_ms,
            'soap_version'      => $this->soap_version,
            'force_endpoint'    => $this->force_location ? 1 : 0,
            'request_bytes'     => strlen( (string) $req ),
            'response_bytes'    => strlen( (string) $res ),
            'request_hdr_sz'    => strlen( (string) $reqH ),
            'response_hdr_sz'   => strlen( (string) $resH ),
            'memory_peak_kb'    => (int) ( memory_get_peak_usage(true) / 1024 ),
            'time_utc'          => gmdate( 'Y-m-d H:i:s' ),
        ];
        update_option( self::OPTION_LAST_INFOCARDS_META, wp_json_encode( $meta ) );

        Logger::instance()->log( 'SOAP fault on '.$op, $meta );
    }

    private function capture_generic_exception_artifacts( \SoapClient $client, \Throwable $e, string $op, float $start ): void {
        if ( ! $this->debug ) return;
        
        $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
        $req  = method_exists( $client, '__getLastRequest' ) ? $client->__getLastRequest() : '';
        $res  = method_exists( $client, '__getLastResponse' ) ? $client->__getLastResponse() : '';
        $reqH = method_exists( $client, '__getLastRequestHeaders' ) ? $client->__getLastRequestHeaders() : '';
        $resH = method_exists( $client, '__getLastResponseHeaders' ) ? $client->__getLastResponseHeaders() : '';

        update_option( self::OPTION_LAST_REQUEST_XML,      $this->excerpt( $req, 5000 ) );
        update_option( self::OPTION_LAST_RAW_XML,          $this->excerpt( $res, 5000 ) );
        update_option( self::OPTION_LAST_SOAP_FAULT,       'Exception: '.$e->getMessage() );
        update_option( self::OPTION_LAST_REQUEST_HEADERS,  $this->excerpt( $reqH, 4000 ) );
        update_option( self::OPTION_LAST_RESPONSE_HEADERS, $this->excerpt( $resH, 4000 ) );

        $meta = [
            'op'                => $op,
            'exception'         => $e->getMessage(),
            'duration_ms'       => $duration_ms,
            'soap_version'      => $this->soap_version,
            'force_endpoint'    => $this->force_location ? 1 : 0,
            'request_bytes'     => strlen( (string) $req ),
            'response_bytes'    => strlen( (string) $res ),
            'request_hdr_sz'    => strlen( (string) $reqH ),
            'response_hdr_sz'   => strlen( (string) $resH ),
            'memory_peak_kb'    => (int) ( memory_get_peak_usage(true) / 1024 ),
            'time_utc'          => gmdate( 'Y-m-d H:i:s' ),
        ];
        update_option( self::OPTION_LAST_INFOCARDS_META, wp_json_encode( $meta ) );

        Logger::instance()->log( 'Exception on '.$op, $meta );
    }

    private function parse_information_cards( mixed $response ): array {
        $arr = json_decode( json_encode( $response ), true );
        $rawRows = $arr['return']['InformationCardsRow'] ?? [];
        
        if ( $rawRows && isset( $rawRows['Inn'] ) && ! isset( $rawRows[0] ) ) {
            $rawRows = [ $rawRows ];
        }
        
        $cards = [];
        if ( ! is_array( $rawRows ) ) return $cards;
        
        foreach ( $rawRows as $row ) {
            if ( ! is_array( $row ) ) continue;
            
            $cards[] = [
                'Inn'                => $row['Inn'] ?? '',
                'Name'               => $row['Name'] ?? '',
                'MobileNumber'       => $row['MobileNumber'] ?? '',
                'DateOfBirth'        => $this->normalize_date_string( $row['DateOfBirth'] ?? '' ),
                'CardCode'           => $row['CardCode'] ?? '',
                'DiscountPercentage' => $this->normalize_discount( $row['DiscountPercentage'] ?? 0 ),
                'IsDeleted'          => $this->to_bool( $row['IsDeleted'] ?? false ),
            ];
        }
        
        if ( $this->debug && ! $cards ) {
            Logger::instance()->log( 'Parsed zero InformationCards (rawRows length: '.(is_countable($rawRows)?count($rawRows):'n/a').')' );
        }
        
        return $cards;
    }

    /**
     * Parse and normalize the Products SOAP response.
     *
     * @param mixed $response Raw SOAP response object.
     * @return array Normalized array of products.
     */
    private function parse_products_response( mixed $response ): array {
        $arr = json_decode( json_encode( $response ), true );
        $rawRows = $arr['return']['ProductsRow'] ?? [];

        // Handle single product response (non-array wrapper)
        if ( $rawRows && isset( $rawRows['VendorCode'] ) && ! isset( $rawRows[0] ) ) {
            $rawRows = [ $rawRows ];
        }

        $products = [];
        if ( ! is_array( $rawRows ) ) {
            return $products;
        }

        foreach ( $rawRows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $products[] = [
                'ProductName' => $this->sanitize_string( $row['ProductName'] ?? '' ),
                'VendorCode'  => $this->sanitize_string( $row['VendorCode'] ?? '' ),
                'Brand'       => $this->sanitize_string( $row['Brand'] ?? '' ),
                'gender'      => $this->sanitize_string( $row['gender'] ?? '' ),
                'Bracelet'    => $this->sanitize_string( $row['Bracelet'] ?? '' ),
                'Color'       => $this->sanitize_string( $row['Color'] ?? '' ),
                'Mechanism'   => $this->sanitize_string( $row['Mechanism'] ?? '' ),
                'Size'        => $this->sanitize_string( $row['Size'] ?? '' ),
                'Bijouterie'  => $this->sanitize_string( $row['Bijouterie'] ?? '' ),
            ];
        }

        if ( $this->debug && ! $products ) {
            Logger::instance()->log( 'Parsed zero Products (rawRows length: ' . ( is_countable( $rawRows ) ? count( $rawRows ) : 'n/a' ) . ')' );
        }

        return $products;
    }

    /**
     * Parse and normalize the GetProductsStock SOAP response.
     *
     * @param mixed $response Raw SOAP response object.
     * @return array Normalized array of stock/price data.
     */
    private function parse_stock_response( mixed $response ): array {
        $arr = json_decode( json_encode( $response ), true );
        $rawRows = $arr['return']['ProductsStockRow'] ?? [];

        // Handle single stock row response (non-array wrapper)
        if ( $rawRows && isset( $rawRows['VendorCode'] ) && ! isset( $rawRows[0] ) ) {
            $rawRows = [ $rawRows ];
        }

        $stock_data = [];
        if ( ! is_array( $rawRows ) ) {
            return $stock_data;
        }

        foreach ( $rawRows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $stock_data[] = [
                'VendorCode' => $this->sanitize_string( $row['VendorCode'] ?? '' ),
                'Quantity'   => $this->to_float( $row['Quantity'] ?? 0 ),
                'Price'      => $this->to_float( $row['Price'] ?? 0 ),
            ];
        }

        if ( $this->debug && ! $stock_data ) {
            Logger::instance()->log( 'Parsed zero ProductsStock (rawRows length: ' . ( is_countable( $rawRows ) ? count( $rawRows ) : 'n/a' ) . ')' );
        }

        return $stock_data;
    }

    /**
     * Sanitize and trim a string value.
     *
     * @param mixed $value Input value.
     * @return string Sanitized and trimmed string.
     */
    private function sanitize_string( mixed $value ): string {
        return trim( (string) $value );
    }

    /**
     * Convert value to float with proper handling.
     * Note: Supports European decimal format (comma as decimal separator)
     * which may be returned by the IBS WebService.
     *
     * @param mixed $value Input value.
     * @return float Converted float value.
     */
    private function to_float( mixed $value ): float {
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }
        // Handle string numbers with comma decimal separator (European format from IBS WebService)
        if ( is_string( $value ) ) {
            $value = str_replace( ',', '.', trim( $value ) );
            if ( is_numeric( $value ) ) {
                return (float) $value;
            }
        }
        return 0.0;
    }

    private function normalize_discount( mixed $val ): int {
        $f = (float) $val;
        if ( $f < 0 ) $f = 0;
        return (int) round( $f );
    }

    private function normalize_date_string( mixed $value ): string {
        $v = trim( (string) $value );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) return $v;
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $v, $m ) ) return "{$m[1]}-{$m[2]}-{$m[3]}";
        return $v;
    }

    private function to_bool( mixed $val ): bool {
        if ( is_bool( $val ) ) return $val;
        $val = strtolower( (string) $val );
        return in_array( $val, [ '1','true','yes','y' ], true );
    }

    private function excerpt( mixed $text, int $limit ): string {
        $t = (string)$text;
        if ( strlen( $t ) > $limit ) {
            return substr( $t, 0, $limit ) . '...[truncated]';
        }
        return $t;
    }

    private function safe_len( mixed $val ): int {
        return strlen( (string) $val );
    }
}
