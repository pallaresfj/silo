<?php

use App\Support\GoogleDriveUrl;

it('appends authuser and removes ouid from stored google url', function () {
    $url = GoogleDriveUrl::resolve(
        storedUrl: 'https://docs.google.com/document/d/abc123/edit?usp=drivesdk&ouid=999',
        fileId: 'abc123',
        email: 'docente@colegio.edu',
    );

    expect($url)
        ->toContain('https://docs.google.com/document/d/abc123/edit')
        ->toContain('authuser=docente%40colegio.edu')
        ->not->toContain('ouid=');
});

it('falls back to file id when stored url is empty', function () {
    $url = GoogleDriveUrl::resolve(
        storedUrl: null,
        fileId: 'file-42',
        email: null,
    );

    expect($url)->toBe('https://drive.google.com/file/d/file-42/view');
});

