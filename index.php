<?php

class TDTCloudinaryForwarder
{
    private $cloudName;
    private $cloudMapping;

    private $imgRequestURL;
    private $imgRequestRegex = "/wp-content\/uploads\/([a-z\-_0-9\/\:\.]*)?\.(jpe?g|png|gif|webp|avif|tiff)/i";

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

    private function getRequestURL($is_forwarded = false)
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
        $theURL = $this->cloudMapping . '/' . $this->imgName . '.' . $this->imgExtension;

        // From tuandev-s3/2022/01/cloudflare-chrome.png to f_auto/tuandev-s3/2022/01/cloudflare-chrome.png
        $theURL  = $this->getImageSizing() . $this->getImageFormatByAcceptHeader() . '/' . $theURL;

        // Prepend /upload/ to the URL
        $theURL = '/upload/' . $theURL;

        $theURL = $this->cloudUploadEndpoint . ($this->isVideoRequest() ? '/video' : '/image') . $theURL;

        return $theURL;
    }

    private function getImageSizing()
    {
        if ($this->isResizeRequest() === false) {
            return '';
        }

        $sizing = '';
        $sizing .= $this->imgSize['width'] > 0 ? ',w_' . $this->imgSize['width'] : '';
        $sizing .= $this->imgSize['height'] > 0 ? ',h_' . $this->imgSize['height'] : '';

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

            $this->imgSize = [];
            if (preg_match_all('/-([0-9]+)x([0-9]+)$/', $this->imgName, $s_matches)) {
                $this->imgSize = [
                    'width' => $s_matches[1][0],
                    'height' => $s_matches[2][0]
                ];
                // To remove the size from the filename
                $this->imgName = preg_replace('/-([0-9]+)x([0-9]+)$/', '', $this->imgName);
            }

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

    private function isResizeRequest()
    {
        return !empty($this->imgSize);
    }

    // If the extension is video format, return true
    private function isVideoRequest()
    {
        if (in_array($this->imgExtension, ['mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'flac', 'aac', 'm4a', 'm4v', 'mov', 'wmv', 'avi', 'mkv', 'mpg', 'mpeg', '3gp', '3g2'])) {
            return true;
        }
        return false;
    }
}

$newWPImgCloudinary = new TDTCloudinaryForwarder();
$newWPImgCloudinary->saveImg();