<?php

class TDTCloudinaryForwarder
{
    private $cloudName;
    private $cloudMapping;

    private $imgRequestURL;
    private $imgRequestRegex = "/^(?:.*(?:\/wp-content\/uploads\/)(.*)-([0-9]{1,4})(?:x)([0-9]{1,4})).*\.(jpe?g|gif|png)$/";
    private $imgRequestRegexOriginal = "/^(?:.*(?:\/wp-content\/uploads\/)(.*)).*\.(jpe?g|gif|png|mp4)$/";

    private $imgName;
    private $imgExtension;
    private $imgSize;

    // Constructor
    public function __construct()
    {
        $this->cloudName = getenv('CLOUDINARY_CLOUD_NAME');
        $this->cloudMapping = getenv('CLOUDINARY_CLOUD_MAPPING');
        $this->cloudUploadEndpoint = 'https://res.cloudinary.com/' . $this->cloudName;

        $this->getRequestImg();
    }

    public function getRequestURL($is_fowarded = false)
    {
        $ssl      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on');
        $port     = $_SERVER['SERVER_PORT'];
        $port     = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
        $http_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
        $host     = ($is_fowarded && isset($_SERVER['HTTP_X_FORWARDED_HOST'])) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $http_host;
        $host     = isset($host) ? $host : $_SERVER['SERVER_NAME'] . $port;
        return 'https://' . $host . $_SERVER['REQUEST_URI'];
    }

    private function getCloudinaryURL()
    {
        // From /2022/01/cloudflare-chrome.png to tuandev-s3/2022/01/cloudflare-chrome.png
        $theURL = $this->cloudMapping . '/' . $this->imgName . '.' . $this->imgExtension;

        // From tuandev-s3/2022/01/cloudflare-chrome.png to f_auto/tuandev-s3/2022/01/cloudflare-chrome.png
        $theURL  = $this->getImageSizing() . $this->getImageFormatByAcceptHeader() . '/' . $theURL;

        // Add endpoint to the start
        $theURL = '/upload/' . $theURL;

        $theURL = $this->cloudUploadEndpoint . ($this->isVideoRequest() ? '/video' : '/image') . $theURL;

        return $theURL;
    }

    private function getImageSizing()
    {
        if ($this->isResizeRequest() === false) {
            return '';
        }
        return 'c_scale,w_' . $this->imgSize['width'] . ',h_' . $this->imgSize['height'] . '/';
    }

    private function getImageFormatByAcceptHeader()
    {
        if (strpos("mp4|webm|ogg|ogv|mp3|wav|flac|aac|m4a|m4v|mov|wmv|avi|mkv|mpg|mpeg|3gp|3g2|gif", $this->imgExtension) !== false) {
            return 'f_auto,q_auto';
        }
        return $this->CloudinaryMapByAcceptHeader();
    }

    private function CloudinaryMapByAcceptHeader()
    {
        switch (true) {
            case stristr($_SERVER['HTTP_ACCEPT'], 'image/avif'):
                return 'f_avif,q_auto:best';
            case stristr($_SERVER['HTTP_ACCEPT'], 'image/webp'):
                return 'f_webp,q_auto:best';
            default:
                return 'f_auto,q_auto:best';
        }
    }

    private function getRequestImg()
    {
        $this->imgRequestURL = $this->getRequestURL();

        if (preg_match_all($this->imgRequestRegex, $this->imgRequestURL, $matches)) {
            $this->imgName = $matches[1][0];
            $this->imgSize = [
                'width' => $matches[2][0],
                'height' => $matches[3][0]
            ];
            $this->imgExtension = strtolower($matches[4][0]);
        } elseif (preg_match_all($this->imgRequestRegexOriginal, $this->imgRequestURL, $matches)) {
            $this->imgName = $matches[1][0];
            $this->imgSize = [];
            $this->imgExtension = strtolower($matches[2][0]);
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

    // check $this->imgSize is not empty
    private function isResizeRequest()
    {
        return !empty($this->imgSize);
    }

    private function isVideoRequest()
    {
        if (in_array($this->imgExtension, ['mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'flac', 'aac', 'm4a', 'm4v', 'mov', 'wmv', 'avi', 'mkv', 'mpg', 'mpeg', '3gp', '3g2'])) {
            return true;
        }
    }
}

$newWPImgCloudinary = new TDTCloudinaryForwarder();
$newWPImgCloudinary->saveImg();
