<?php

use Aws\S3\S3Client;

class R2Storage {

    private static function client() {

        return new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => getenv('R2_ENDPOINT'),
            'credentials' => [
                'key' => getenv('R2_ACCESS_KEY'),
                'secret' => getenv('R2_SECRET_KEY'),
            ],
        ]);
    }

    public static function upload($filePath, $key, $options = []) {

        $client = self::client();

        $params = [
            'Bucket' => getenv('R2_BUCKET'),
            'Key' => $key,
            'SourceFile' => $filePath,
            'ContentType' => mime_content_type($filePath),
        ];

        // 👇 si quieres forzar descarga
        if (!empty($options['download'])) {
            $params['ContentDisposition'] = 'attachment; filename="' . basename($key) . '"';
        }

        $client->putObject($params);

        return getenv('R2_PUBLIC_URL') . '/' . $key;
    }
}