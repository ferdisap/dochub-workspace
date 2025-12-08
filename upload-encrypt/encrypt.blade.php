<!-- resources/views/upload.blade.php -->
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Dochub Upload') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <meta name="csrf-token" content="{{ csrf_token() }}">

    @vite(['resources/views/upload-encrypt/main.ts'])
        
</head>

<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h2>File Upload</h2>
                        <p class="text-muted">Upload ZIP files for workspace synchronization</p>
                    </div>
                    <div class="card-body" id="app">
                      <input type="file" id="fileInput"/>
                    </div>
                    <div class="card-body" id="app">
                      <button type="button" id="downloadFile">download</button>
                    </div>
                    <div class="card-body" id="preview">
                      <canvas id="pdf-canvas" type="application/pdf"/>
                    </div>
                </div>

                <!-- Status Section -->
                <div class="card mt-4" id="status-section" style="display:none">
                    <div class="card-header">
                        <h3>Processing Status</h3>
                    </div>
                    <div class="card-body">
                        <div id="status-content"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>