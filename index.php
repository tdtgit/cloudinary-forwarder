<?php

class CloudinaryForwarder
{
    private $cloudName;
    private $cloudMapping;
    private $cloudUploadEndpoint;

    private $resourceRequestURL;
    private $resourceRequestRegex = "/wp-content\/uploads\/(.*?)\.(jpe?g|png|gif|webp|avif|tiff|mp4|webm|pdf)/i";

    private $resourceName;
    private $resourceExtension;
    private $resourceSize = ['width' => 0, 'height' => 0];

    private $enableImgHeightVerify = false;
    private $wp_image_sizes = [
        'medium' => 300,
        'large' => 1024,
        'enigma-mobile' => 325,
        'enigma-mobile-2x' => 650,
        'enigma-mobile-3x' => 975,
        'enigma-desktop' => 750,
        'enigma-desktop-2x' => 1500,
        'enigma-desktop-3x' => 2250,
    ];

    // Constructor
    public function __construct()
    {
        $this->cloudName = getenv('CLOUDINARY_CLOUD_NAME') ?: defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : '';
        $this->cloudMapping = getenv('CLOUDINARY_CLOUD_MAPPING') ?: defined('CLOUDINARY_CLOUD_MAPPING') ? CLOUDINARY_CLOUD_MAPPING : '';

        if (!$this->cloudName || !$this->cloudMapping) {
            throw new Exception('Cloudinary configuration is missing');
        }

        $this->cloudUploadEndpoint = 'https://res.cloudinary.com/' . $this->cloudName;

        $this->getRequestedImg();
    }

    private function getRequestedURL($isForwarded = false)
    {
        $ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on');
        $port = ($_SERVER['SERVER_PORT'] != '80' && $_SERVER['SERVER_PORT'] != '443') ? ':' . $_SERVER['SERVER_PORT'] : '';
        $host = $isForwarded && isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $_SERVER['HTTP_HOST'];

        return 'https://' . $host . $port . $_SERVER['REQUEST_URI'];
    }

    private function getCloudinaryURL()
    {
        $url = '/upload/' . $this->getImageSizing() . $this->getImageFormat() . '/' . $this->cloudMapping . '/' . $this->resourceName . '.' . $this->resourceExtension;

        return $this->cloudUploadEndpoint . ($this->isVideoRequest() ? '/video' : '/image') . $url;
    }

    private function isValidImageSize()
    {
        return isset($this->wp_image_sizes[$this->resourceSize['width']]);
    }

    private function getImageSizing()
    {
        if (!$this->isResizeRequest()) {
            return '';
        }

        $sizing = 'c_scale';
        $sizing .= $this->resourceSize['width'] ? ',w_' . $this->resourceSize['width'] : '';
        $sizing .= $this->enableImgHeightVerify && $this->resourceSize['height'] ? ',h_' . $this->resourceSize['height'] : '';

        return $sizing . '/';
    }

    private function getImageFormat()
    {
        if ($this->isVideoRequest()) {
            return 'f_auto,q_auto';
        }

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

        if (preg_match($this->resourceRequestRegex, $this->resourceRequestURL, $matches)) {
            $this->resourceName = $matches[1];
            $this->resourceExtension = strtolower($matches[2]);

            if (preg_match('/-([0-9]+)x([0-9]+)$/', $this->resourceName, $sizeMatches)) {
                $this->resourceSize = [
                    'width' => $sizeMatches[1],
                    'height' => $sizeMatches[2]
                ];

                if (!$this->isValidImageSize()) {
                    http_response_code(404);
                    exit;
                }

                $this->resourceName = preg_replace('/-([0-9]+)x([0-9]+)$/', '', $this->resourceName);
            }
        } else {
            http_response_code(404);
            exit;
        }
    }

    public function saveImg()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_URL, $this->getCloudinaryURL());

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) {
                return $len;
            }
            $responseHeaders[strtolower(trim($header[0]))][] = trim($header[1]);
            return $len;
        });

        $responseBody = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        http_response_code($responseCode);
        foreach ($responseHeaders as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value);
            }
        }

        header("x-service: cloudinary-forwarder");
        echo $responseBody;
    }

    private function isResizeRequest()
    {
        return $this->resourceSize['width'] > 0 && $this->resourceSize['height'] > 0;
    }

    private function isVideoRequest()
    {
        $videoExtensions = ['mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'flac', 'aac', 'm4a', 'm4v', 'mov', 'wmv', 'avi', 'mkv', 'mpg', 'mpeg', '3gp', '3g2'];
        return in_array($this->resourceExtension, $videoExtensions);
    }
}

$cloudinaryForwarder = new CloudinaryForwarder();
$cloudinaryForwarder->saveImg();
