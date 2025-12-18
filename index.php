<?php
// --- LÓGICA DE PROCESAMIENTO PHP (para el convertidor) ---
$error_message = '';

function convertFile($file_tmp_path, $file_original_name, $upload_dir, $ghostscript_path) {
    if (!is_dir($upload_dir)) { if (!mkdir($upload_dir, 0755, true)) { return ['error' => 'Error: No se pudo crear el directorio temporal.']; } }
    
    $unique_id = uniqid('pdf_', true);
    $original_path = $upload_dir . '/' . $unique_id . '_original.pdf';
    $converted_filename = pathinfo($file_original_name, PATHINFO_FILENAME) . '_v1.4.pdf';
    $converted_path = $upload_dir . '/' . $unique_id . '_' . $converted_filename; // Keep original name in preserved file for zip

    if (move_uploaded_file($file_tmp_path, $original_path)) {
        $command = sprintf('%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/default -dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s', escapeshellarg($ghostscript_path), escapeshellarg($converted_path), escapeshellarg($original_path));
        $return_code = null;
        exec($command, $output, $return_code);
        
        if (file_exists($original_path)) unlink($original_path); // Cleanup original immediately

        if ($return_code === 0 && file_exists($converted_path)) {
            return ['success' => true, 'path' => $converted_path, 'filename' => $converted_filename];
        } else {
             return ['error' => 'Error durante la conversión del PDF: ' . $file_original_name];
        }
    } else {
        return ['error' => 'Error al mover el archivo subido: ' . $file_original_name];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert') {
    if (isset($_FILES['pdf_file'])) {
        $files = $_FILES['pdf_file'];
        $upload_dir = 'temp_files';
        $ghostscript_path = $_SERVER['HOME'] . '/bin/gs';
        
        // Normalize file array structure
        $file_ary = array();
        if (is_array($files['name'])) {
            $file_count = count($files['name']);
            for ($i = 0; $i < $file_count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                   $file_ary[] = array(
                       'name' => $files['name'][$i],
                       'type' => $files['type'][$i],
                       'tmp_name' => $files['tmp_name'][$i],
                       'error' => $files['error'][$i],
                       'size' => $files['size'][$i]
                   );
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                $file_ary[] = $files;
            }
        }

        if (count($file_ary) > 0) {
            $converted_files = [];
            $errors = [];

            foreach ($file_ary as $file) {
                $file_type = mime_content_type($file['tmp_name']);
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if ($file_type === 'application/pdf' && $file_ext === 'pdf') {
                    $result = convertFile($file['tmp_name'], $file['name'], $upload_dir, $ghostscript_path);
                    if (isset($result['success'])) {
                        $converted_files[] = $result;
                    } else {
                        $errors[] = $result['error'];
                    }
                } else {
                    $errors[] = 'Formato no válido (se requiere PDF): ' . $file['name'];
                }
            }

            if (!empty($errors)) {
                $error_message = implode('<br>', $errors);
            }

            if (!empty($converted_files)) {
                if (count($converted_files) === 1 && empty($errors)) {
                    // Single file download
                    $file_data = $converted_files[0];
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $file_data['filename'] . '"');
                    header('Expires: 0');
                    header('Content-Length: ' . filesize($file_data['path']));
                    ob_clean();
                    flush();
                    readfile($file_data['path']);
                    unlink($file_data['path']);
                    exit;
                } elseif (count($converted_files) > 0) {
                    // Multiple files -> ZIP
                    $zip = new ZipArchive();
                    $zip_filename = 'converted_pdfs_' . date('Ymd_His') . '.zip';
                    $zip_path = $upload_dir . '/' . $zip_filename;

                    if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
                        foreach ($converted_files as $cf) {
                            $zip->addFile($cf['path'], $cf['filename']);
                        }
                        $zip->close();

                        // Cleanup converted files after adding to zip
                        foreach ($converted_files as $cf) {
                            unlink($cf['path']);
                        }

                        header('Content-Type: application/zip');
                        header('Content-disposition: attachment; filename='.$zip_filename);
                        header('Content-Length: ' . filesize($zip_path));
                        ob_clean();
                        flush();
                        readfile($zip_path);
                        unlink($zip_path);
                        exit;
                    } else {
                        $error_message = 'Error al crear el archivo ZIP.';
                    }
                }
            } elseif (empty($error_message)) {
                 $error_message = "No se pudieron convertir los archivos.";
            }

        } else {
             $error_message = 'No se seleccionó ningún archivo o hubo un error en la subida.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Herramienta PDF</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 0;
            padding: 2rem 0;
            background-color: #f0f2f5;
            color: #1c1e21;
            min-height: 100vh;
        }
        .container {
            background-color: #ffffff;
            padding: 2rem 3rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 400px;
            width: 90%;
            margin-bottom: 2rem;
        }
        h1 { font-size: 1.5rem; margin-top: 0; margin-bottom: 1.5rem; }
        .upload-form { display: flex; flex-direction: column; gap: 1rem; }
        .file-input-wrapper {
            border: 2px dashed #ccd0d5;
            border-radius: 6px;
            padding: 2rem;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .file-input-wrapper:hover, .file-input-wrapper.drag-over {
            background-color: #f7f8fa;
            border-color: #1877f2;
        }
        input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .file-label { color: #606770; display: block; pointer-events: none; }
        .submit-btn {
            background-color: #1877f2;
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .submit-btn:hover { background-color: #166fe5; }
        .error-message { color: #fa383e; font-size: 0.9rem; margin-top: 1rem; min-height: 1em; }
        .footer-text { text-align: center; color: #606770; font-size: 0.85rem; margin-top: 0; }
        #verification-result { font-weight: bold; padding: 0.5rem; border-radius: 4px; margin-top: 1rem; display: none; }
        .result-success { color: #1d643b; background-color: #dcfce7; display: block; }
        .result-error { color: #991b1b; background-color: #fee2e2; display: block; }
        .selected-files {
            margin-top: 1rem;
            font-size: 0.85rem;
            color: #1c1e21;
            text-align: left;
            max-height: 100px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Convertir PDF a Versión 1.4</h1>
        <?php if (!empty($error_message)): ?>
            <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="upload-form" action="index.php" id="convert-form">
            <input type="hidden" name="action" value="convert">
            <div class="file-input-wrapper" id="drop-zone-convert">
                <span class="file-label" id="file-name-convert">Haz clic o arrastra PDFs aquí</span>
                <input type="file" name="pdf_file[]" id="pdf_file_convert" accept=".pdf" required multiple>
            </div>
            <div id="file-list-convert" class="selected-files"></div>
            <button type="submit" class="submit-btn" id="convert-btn">Convertir y Descargar</button>
        </form>
    </div>
    <div class="container">
        <h1>Verificar Versión de PDF</h1>
        <br>
        <div class="file-input-wrapper" id="drop-zone-verify">
             <span class="file-label" id="file-name-verify">Haz clic o arrastra un PDF aquí</span>
             <input type="file" id="pdf_file_verify" accept=".pdf">
        </div>
        <p id="verification-result"></p>
        <br>
    </div>
    <p class="footer-text">Creado por Moises con amor para Ara♥️</p>
    <script>
        // --- Convert Logic ---
        const convertInput = document.getElementById('pdf_file_convert');
        const convertDropZone = document.getElementById('drop-zone-convert');
        const convertLabel = document.getElementById('file-name-convert');
        const convertFileList = document.getElementById('file-list-convert');

        function updateConvertUI(files) {
            if (files.length > 0) {
                if (files.length === 1) {
                    convertLabel.textContent = files[0].name;
                    convertFileList.textContent = '';
                } else {
                    convertLabel.textContent = `${files.length} archivos seleccionados`;
                    let listHtml = '<ul>';
                    for (let i = 0; i < files.length; i++) {
                        listHtml += `<li>${files[i].name}</li>`;
                    }
                    listHtml += '</ul>';
                    convertFileList.innerHTML = listHtml;
                }
            } else {
                convertLabel.textContent = 'Haz clic o arrastra PDFs aquí';
                convertFileList.textContent = '';
            }
        }

        convertInput.addEventListener('change', () => {
             updateConvertUI(convertInput.files);
        });

        // Drag & Drop visual feedback
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            convertDropZone.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false); // Prevent drop on body opening file
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            convertDropZone.addEventListener(eventName, highlightConvert, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            convertDropZone.addEventListener(eventName, unhighlightConvert, false);
        });

        function highlightConvert() { convertDropZone.classList.add('drag-over'); }
        function unhighlightConvert() { convertDropZone.classList.remove('drag-over'); }

        convertDropZone.addEventListener('drop', handleDropConvert, false);

        function handleDropConvert(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            // Assign files to input
            if (files.length > 0) {
                 convertInput.files = files;
                 updateConvertUI(files);
            }
        }

        // --- Verify Logic ---
        const verifyInput = document.getElementById('pdf_file_verify');
        const verifyDropZone = document.getElementById('drop-zone-verify');
        const verifyLabel = document.getElementById('file-name-verify');
        const resultDisplay = document.getElementById('verification-result');

        verifyInput.addEventListener('change', (event) => {
            handleVerifyFile(event.target.files[0]);
        });
        
        // Verify Drag & Drop
        ['dragenter', 'dragover'].forEach(eventName => {
            verifyDropZone.addEventListener(eventName, () => verifyDropZone.classList.add('drag-over'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            verifyDropZone.addEventListener(eventName, () => verifyDropZone.classList.remove('drag-over'), false);
        });
        
        verifyDropZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files.length > 0) {
                // Manually trigger check since setting .files doesn't trigger change event automatically in all cases/browsers immediately used same way
                // But we can just call our handler
                handleVerifyFile(files[0]);
                // Still set it to input for consistency if needed, though we just read it
                verifyInput.files = files; 
            }
        }, false);

        function handleVerifyFile(file) {
            if (file) {
                verifyLabel.textContent = file.name;
                const reader = new FileReader();
                reader.onload = function(e) {
                    const arr = (new Uint8Array(e.target.result)).subarray(0, 10);
                    const header = new TextDecoder("utf-8").decode(arr);
                    resultDisplay.style.display = 'block';
                    if (header.startsWith('%PDF-1.4')) {
                        resultDisplay.textContent = '¡Correcto! La versión del PDF es 1.4.';
                        resultDisplay.className = 'result-success';
                    } else if (header.startsWith('%PDF-')) {
                        const version = header.substring(5, 8);
                        resultDisplay.textContent = `Este PDF no es versión 1.4. Es versión ${version}.`;
                        resultDisplay.className = 'result-error';
                    } else {
                        resultDisplay.textContent = 'El archivo seleccionado no parece ser un PDF válido.';
                        resultDisplay.className = 'result-error';
                    }
                };
                reader.readAsArrayBuffer(file);
            } else {
                verifyLabel.textContent = 'Haz clic o arrastra un PDF aquí';
                resultDisplay.style.display = 'none';
            }
        }
    </script>
</body>
</html>
