<?php

class TDTCloudinaryForwarder
{
    private $cloudName;
    private $cloudMapping;

    private $resourceRequestURL;
    private $resourceRequestRegex = "/wp-content\/uploads\/(.*?)\.(jpe?g|png|gif|webp|avif|tiff|mp4|webm|pdf)/i";

    private $resourceName;
    private $resourceExtension;
    private $resourceSize;

    // Constructor
    public function __construct()
    {
        $this->cloudName = getenv('CLOUDINARY_CLOUD_NAME') ?: CLOUDINARY_CLOUD_NAME;
        $this->cloudMapping = getenv('CLOUDINARY_CLOUD_MAPPING') ?: CLOUDINARY_CLOUD_MAPPING;
        $this->cloudUploadEndpoint = 'https://res.cloudinary.com/' . $this->cloudName;

        if (empty($this->cloudName) || empty($this->cloudMapping)) {
            throw new Exception('Cloudinary configuration is missing');
        }

        $this->enableImgHeightVerify = false;

        $this->getRequestedImg();
    }

    private function getRequestedURL($is_forwarded = false)
    {
        $ssl      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on');
        $port     = $_SERVER['SERVER_PORT'];
        $port     = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
        $http_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
        $host     = ($is_forwarded && isset($_SERVER['HTTP_X_FORWARDED_HOST'])) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $http_host;
        $host     = isset($host) ? $host : $_SERVER['SERVER_NAME'] . $port;
        return 'https://' . $host . $_SERVER['REQUEST_URI'];
    }

    private function getCloudinaryURL()
    {
        // From /2022/01/cloudflare-chrome.png to tuandev-s3/2022/01/cloudflare-chrome.png
        $theURL = $this->cloudMapping . '/' . $this->resourceName . '.' . $this->resourceExtension;

        // From tuandev-s3/2022/01/cloudflare-chrome.png to f_auto/tuandev-s3/2022/01/cloudflare-chrome.png
        $theURL  = $this->getImageSizing() . $this->getImageFormatByAcceptHeader() . '/' . $theURL;

        // Prepend /upload/ to the URL
        $theURL = '/upload/' . $theURL;

        $theURL = $this->cloudUploadEndpoint . ($this->isVideoRequest() ? '/video' : '/image') . $theURL;

        return $theURL;
    }

    private function isValidImageSize()
    {
        $wp_image_sizes = array(
            'medium' => array(
                'width'  => 300,
            ),
            'large' => array(
                'width'  => 1024,
            ),
            'enigma-mobile' => array(
                'width'  => 325,
            ),
            'enigma-mobile-2x' => array(
                'width'  => 650,
            ),
            'enigma-mobile-3x' => array(
                'width'  => 975,
            ),
            'enigma-desktop' => array(
                'width'  => 750,
            ),
            'enigma-desktop-2x' => array(
                'width'  => 1500,
            ),
            'enigma-desktop-3x' => array(
                'width'  => 2250,
            ),
        );

        // Check if match any in WordPress's registered size
        foreach ($wp_image_sizes as $size => $size_info) {
            if ($this->resourceSize['width'] == $size_info['width']) {
                return true;
            }

            if ($this->enableImgHeightVerify && $this->resourceSize['height'] == $size_info['height']) {
                return true;
            }
        }

        return false;
    }

    private function getImageSizing()
    {
        if ($this->isResizeRequest() === false) {
            return '';
        }

        $sizing = '';
        $sizing .= $this->resourceSize['width'] > 0 ? ',w_' . $this->resourceSize['width'] : '';
        $sizing .= ($this->enableImgHeightVerify && $this->resourceSize['height'] > 0) ? ',h_' . $this->resourceSize['height'] : '';

        return 'c_scale' . $sizing . '/';
    }

    private function getImageFormatByAcceptHeader()
    {
        if ($this->isVideoRequest()) {
            return 'f_auto,q_auto';
        }
        return $this->CloudinaryMapByAcceptHeader();
    }

    private function CloudinaryMapByAcceptHeader()
    {
        switch (true) {
            case $this->resourceExtension == 'gif':
                return 'f_auto,q_auto:best';
            case stristr($_SERVER['HTTP_ACCEPT'], 'image/avif'):
                return 'f_avif,q_auto:best';
            case stristr($_SERVER['HTTP_ACCEPT'], 'image/webp'):
                return 'f_webp,q_auto:best';
            default:
                return 'f_auto,q_auto:best';
        }
    }

    private function getRequestedImg()
    {
        $this->resourceRequestURL = $this->getRequestedURL();

        if (preg_match_all($this->resourceRequestRegex, $this->resourceRequestURL, $matches)) {
            $this->resourceName = $matches[1][0];

            $this->resourceSize = [];
            if (preg_match_all('/-([0-9]+)x([0-9]+)$/', $this->resourceName, $s_matches)) {
                $this->resourceSize = [
                    'width' => $s_matches[1][0],
                    'height' => $s_matches[2][0]
                ];

                if ($this->isValidImageSize() === false) {
                    http_response_code(404);
                    die();
                }

                // To remove the size from the filename
                $this->resourceName = preg_replace('/-([0-9]+)x([0-9]+)$/', '', $this->resourceName);
            } else {
                $this->resourceSize = [
                    'width' => 0,
                    'height' => 0
                ];
            }

            $this->resourceExtension = strtolower($matches[2][0]);
        } else {
            http_response_code(404);
            die();
        }
    }

    public function saveImg()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_URL, $this->getCloudinaryURL());
        $response_headers = [];
        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            function ($curl, $header) use (&$response_headers) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) {
                    return $len;
                }
                $response_headers[strtolower(trim($header[0]))][] = trim($header[1]);
                return $len;
            }
        );
        $response_body = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        http_response_code($response_code);
        foreach ($response_headers as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value);
            }
        }
        header("x-service: cloudinary-forwarder");
        echo $response_body;
    }

    private function isResizeRequest()
    {
        if ($this->resourceSize['height'] > 0 && $this->resourceSize['width'] > 0) {
            return true;
        }
        return false;
    }

    // If the extension is video format, return true
    private function isVideoRequest()
    {
        if (in_array($this->resourceExtension, ['mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'flac', 'aac', 'm4a', 'm4v', 'mov', 'wmv', 'avi', 'mkv', 'mpg', 'mpeg', '3gp', '3g2'])) {
            return true;
        }
        return false;
    }
}

$newWPImgCloudinary = new TDTCloudinaryForwarder();
$newWPImgCloudinary->saveImg();
