<?php
declare(strict_types=1);

/*
 * MuchoCore Release Manager v2.4
 * Copyright (C) 2026 IZK
 */

const MUCHO_RELEASE_TMP =
    '/var/www/mucho-core/storage/release-uploads';

const MUCHO_ANDROID_RELEASES =
    '/var/www/mucho-core/public/downloads/android';

const MUCHO_RELEASE_MAX_SIZE =
    650 * 1024 * 1024;


function releaseColumnExists(
    PDO $db,
    string $table,
    string $column
): bool {
    $q=$db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema=DATABASE()
          AND table_name=?
          AND column_name=?
        LIMIT 1
    ");

    $q->execute([$table,$column]);

    return (bool)$q->fetchColumn();
}


function ensureReleaseManager(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_client_release_uploads (
            upload_id VARCHAR(64) PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(32) NOT NULL,
            version VARCHAR(32) NOT NULL,
            minimum_version VARCHAR(32) NOT NULL,
            release_notes TEXT NULL,
            original_name VARCHAR(255) NOT NULL,
            temp_name VARCHAR(255) NOT NULL,
            total_size BIGINT UNSIGNED NOT NULL,
            received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,

            KEY idx_release_upload_admin(admin_user_id),
            KEY idx_release_upload_expiry(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_client_release_files (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            platform VARCHAR(32) NOT NULL,
            version VARCHAR(32) NOT NULL,

            file_name VARCHAR(255) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            sha256 VARCHAR(64) NOT NULL,

            signer_sha256 VARCHAR(64) NULL,
            signature_status VARCHAR(32)
                NOT NULL DEFAULT 'unchecked',

            download_url VARCHAR(500) NOT NULL,

            uploaded_by VARCHAR(64) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            KEY idx_release_platform(platform),
            KEY idx_release_version(version),
            KEY idx_release_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (
        !releaseColumnExists(
            $db,
            'mucho_client_releases',
            'signer_sha256'
        )
    ) {
        $db->exec("
            ALTER TABLE mucho_client_releases
            ADD COLUMN signer_sha256 VARCHAR(64) NULL
        ");
    }
}


function releaseJson(
    array $data,
    int $status=200
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


function releaseVersion(string $value): string
{
    $value=trim($value);

    if (
        !preg_match(
            '/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,31}$/',
            $value
        )
    ) {
        throw new RuntimeException(
            'Invalid version.'
        );
    }

    return $value;
}


function cleanupReleaseUploads(PDO $db): void
{
    $q=$db->query("
        SELECT temp_name
        FROM mucho_client_release_uploads
        WHERE expires_at < NOW()
    ");

    foreach($q->fetchAll() as $row) {
        $path=
            MUCHO_RELEASE_TMP.'/'.
            basename((string)$row['temp_name']);

        if(is_file($path)){
            @unlink($path);
        }
    }

    $db->exec("
        DELETE FROM mucho_client_release_uploads
        WHERE expires_at < NOW()
    ");
}


function apkSignerInfo(
    string $apk
): array {
    $binary=trim(
        (string)shell_exec(
            'command -v apksigner 2>/dev/null'
        )
    );

    if($binary===''){
        return [
            'status'=>'unchecked',
            'sha256'=>null
        ];
    }

    $command=
        escapeshellarg($binary).
        ' verify --print-certs '.
        escapeshellarg($apk).
        ' 2>&1';

    $output=[];
    $code=0;

    exec(
        $command,
        $output,
        $code
    );

    if($code!==0){
        throw new RuntimeException(
            'APK signature verification failed.'
        );
    }

    $text=implode("\n",$output);

    if(
        preg_match(
            '/certificate SHA-256 digest:\s*([0-9a-fA-F:]+)/',
            $text,
            $m
        )
    ){
        return [
            'status'=>'verified',
            'sha256'=>strtolower(
                str_replace(':','',$m[1])
            )
        ];
    }

    return [
        'status'=>'verified',
        'sha256'=>null
    ];
}


ensureReleaseManager($db);


/* =========================================================
   AJAX ACTIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD']==='POST') {

    $action=(string)($_POST['action'] ?? '');

    if (
        in_array(
            $action,
            [
                'clientrelease-upload-init',
                'clientrelease-upload-chunk',
                'clientrelease-upload-finalize'
            ],
            true
        )
    ) {
        try {

            checkCsrf();
            requireRank(30);

            cleanupReleaseUploads($db);

            $adminId=(int)(admin()['id'] ?? 0);

            if($adminId<=0){
                throw new RuntimeException(
                    'Invalid admin session.'
                );
            }


            /* INIT */

            if(
                $action===
                'clientrelease-upload-init'
            ){
                $version=releaseVersion(
                    (string)(
                        $_POST['version'] ?? ''
                    )
                );

                $minimum=releaseVersion(
                    (string)(
                        $_POST['minimum_version']
                        ?? $version
                    )
                );

                $notes=trim(
                    (string)(
                        $_POST['release_notes']
                        ?? ''
                    )
                );

                if(
                    mb_strlen($notes,'UTF-8')
                    >10000
                ){
                    throw new RuntimeException(
                        'Release notes are too long.'
                    );
                }

                $original=basename(
                    (string)(
                        $_POST['original_name']
                        ?? 'MuchoGDPS.apk'
                    )
                );

                if(
                    strtolower(
                        pathinfo(
                            $original,
                            PATHINFO_EXTENSION
                        )
                    )!=='apk'
                ){
                    throw new RuntimeException(
                        'APK files only.'
                    );
                }

                $total=(int)(
                    $_POST['total_size'] ?? 0
                );

                if(
                    $total<=0 ||
                    $total>MUCHO_RELEASE_MAX_SIZE
                ){
                    throw new RuntimeException(
                        'Invalid APK size.'
                    );
                }

                $uploadId=
                    bin2hex(random_bytes(24));

                $tempName=
                    $uploadId.'.part';

                $path=
                    MUCHO_RELEASE_TMP.'/'.
                    $tempName;

                if(
                    file_put_contents(
                        $path,
                        '',
                        LOCK_EX
                    )===false
                ){
                    throw new RuntimeException(
                        'Cannot create upload file.'
                    );
                }

                chmod($path,0640);

                $q=$db->prepare("
                    INSERT INTO mucho_client_release_uploads
                    (
                        upload_id,
                        admin_user_id,
                        platform,
                        version,
                        minimum_version,
                        release_notes,
                        original_name,
                        temp_name,
                        total_size,
                        received_bytes,
                        expires_at
                    )
                    VALUES
                    (
                        :upload,
                        :admin,
                        'android',
                        :version,
                        :minimum,
                        :notes,
                        :original,
                        :temp,
                        :total,
                        0,
                        DATE_ADD(NOW(),INTERVAL 24 HOUR)
                    )
                ");

                $q->execute([
                    'upload'=>$uploadId,
                    'admin'=>$adminId,
                    'version'=>$version,
                    'minimum'=>$minimum,
                    'notes'=>$notes ?: null,
                    'original'=>$original,
                    'temp'=>$tempName,
                    'total'=>$total
                ]);

                releaseJson([
                    'ok'=>true,
                    'upload_id'=>$uploadId,
                    'chunk_size'=>1048576
                ]);
            }


            /* CHUNK */

            if(
                $action===
                'clientrelease-upload-chunk'
            ){
                $uploadId=trim(
                    (string)(
                        $_POST['upload_id']
                        ?? ''
                    )
                );

                $offset=(int)(
                    $_POST['offset'] ?? -1
                );

                if(
                    !preg_match(
                        '/^[a-f0-9]{48}$/',
                        $uploadId
                    )
                ){
                    throw new RuntimeException(
                        'Invalid upload ID.'
                    );
                }

                if(
                    !isset($_FILES['chunk']) ||
                    ($_FILES['chunk']['error'] ?? -1)
                        !==UPLOAD_ERR_OK
                ){
                    throw new RuntimeException(
                        'Chunk missing.'
                    );
                }

                $chunkSize=(int)(
                    $_FILES['chunk']['size']
                    ?? 0
                );

                if(
                    $chunkSize<=0 ||
                    $chunkSize>
                    (2*1024*1024)
                ){
                    throw new RuntimeException(
                        'Invalid chunk size.'
                    );
                }

                $q=$db->prepare("
                    SELECT *
                    FROM mucho_client_release_uploads
                    WHERE upload_id=?
                      AND admin_user_id=?
                    LIMIT 1
                ");

                $q->execute([
                    $uploadId,
                    $adminId
                ]);

                $upload=$q->fetch();

                if(!$upload){
                    throw new RuntimeException(
                        'Upload session not found.'
                    );
                }

                $path=
                    MUCHO_RELEASE_TMP.'/'.
                    basename(
                        (string)$upload['temp_name']
                    );

                clearstatcache(true,$path);

                $actual=
                    is_file($path)
                    ? (int)filesize($path)
                    : 0;

                if($offset!==$actual){

                    releaseJson([
                        'ok'=>false,
                        'error'=>'offset_mismatch',
                        'expected_offset'=>$actual
                    ],409);
                }

                $tmp=(string)(
                    $_FILES['chunk']['tmp_name']
                );

                $data=file_get_contents($tmp);

                if($data===false){
                    throw new RuntimeException(
                        'Cannot read chunk.'
                    );
                }

                $written=file_put_contents(
                    $path,
                    $data,
                    FILE_APPEND | LOCK_EX
                );

                if(
                    $written===false ||
                    $written!==strlen($data)
                ){
                    throw new RuntimeException(
                        'Cannot write chunk.'
                    );
                }

                $received=
                    $actual+$written;

                if(
                    $received>
                    (int)$upload['total_size']
                ){
                    throw new RuntimeException(
                        'Upload exceeds expected size.'
                    );
                }

                $q=$db->prepare("
                    UPDATE mucho_client_release_uploads
                    SET
                        received_bytes=?,
                        expires_at=
                            DATE_ADD(
                                NOW(),
                                INTERVAL 24 HOUR
                            )
                    WHERE upload_id=?
                ");

                $q->execute([
                    $received,
                    $uploadId
                ]);

                releaseJson([
                    'ok'=>true,
                    'received_bytes'=>$received,
                    'total_size'=>
                        (int)$upload['total_size']
                ]);
            }


            /* FINALIZE */

            if(
                $action===
                'clientrelease-upload-finalize'
            ){
                $uploadId=trim(
                    (string)(
                        $_POST['upload_id']
                        ?? ''
                    )
                );

                $q=$db->prepare("
                    SELECT *
                    FROM mucho_client_release_uploads
                    WHERE upload_id=?
                      AND admin_user_id=?
                    LIMIT 1
                ");

                $q->execute([
                    $uploadId,
                    $adminId
                ]);

                $upload=$q->fetch();

                if(!$upload){
                    throw new RuntimeException(
                        'Upload session not found.'
                    );
                }

                $temp=
                    MUCHO_RELEASE_TMP.'/'.
                    basename(
                        (string)$upload['temp_name']
                    );

                if(!is_file($temp)){
                    throw new RuntimeException(
                        'Temporary APK missing.'
                    );
                }

                clearstatcache(true,$temp);

                $actual=(int)filesize($temp);
                $expected=(int)$upload['total_size'];

                if($actual!==$expected){
                    throw new RuntimeException(
                        "Incomplete upload: ".
                        $actual." / ".$expected
                    );
                }

                $fh=fopen($temp,'rb');

                if(!$fh){
                    throw new RuntimeException(
                        'Cannot inspect APK.'
                    );
                }

                $magic=fread($fh,4);
                fclose($fh);

                if(
                    !is_string($magic) ||
                    substr($magic,0,2)!=='PK'
                ){
                    throw new RuntimeException(
                        'File is not a valid APK/ZIP.'
                    );
                }

                if(class_exists('ZipArchive')){

                    $zip=new ZipArchive();

                    if(
                        $zip->open($temp)
                        !==true
                    ){
                        throw new RuntimeException(
                            'Cannot open APK archive.'
                        );
                    }

                    $manifest=
                        $zip->locateName(
                            'AndroidManifest.xml'
                        );

                    $zip->close();

                    if($manifest===false){
                        throw new RuntimeException(
                            'AndroidManifest.xml missing.'
                        );
                    }
                }

                $sha=hash_file(
                    'sha256',
                    $temp
                );

                if(
                    !is_string($sha) ||
                    strlen($sha)!==64
                ){
                    throw new RuntimeException(
                        'Cannot calculate SHA-256.'
                    );
                }

                $signer=
                    apkSignerInfo($temp);

                $q=$db->prepare("
                    SELECT signer_sha256
                    FROM mucho_client_releases
                    WHERE platform='android'
                    LIMIT 1
                ");

                $q->execute();

                $expectedSigner=
                    $q->fetchColumn();

                if(
                    is_string($expectedSigner) &&
                    $expectedSigner!=='' &&
                    is_string($signer['sha256']) &&
                    $signer['sha256']!=='' &&
                    !hash_equals(
                        strtolower($expectedSigner),
                        strtolower($signer['sha256'])
                    )
                ){
                    throw new RuntimeException(
                        'APK signing certificate does not match the existing Mucho release.'
                    );
                }

                $safeVersion=
                    preg_replace(
                        '/[^0-9A-Za-z._\-]+/',
                        '_',
                        (string)$upload['version']
                    );

                $fileName=
                    'MuchoGDPS_'.
                    $safeVersion.
                    '_'.
                    date('Ymd_His').
                    '.apk';

                $final=
                    MUCHO_ANDROID_RELEASES.'/'.
                    $fileName;

                if(!rename($temp,$final)){
                    throw new RuntimeException(
                        'Cannot publish APK.'
                    );
                }

                chmod($final,0644);

                $baseUrl=rtrim(
                    (string)(
                        getenv('MUCHO_ACCOUNT_URL')
                        ?: (
                            'https://'.
                            (string)($_SERVER['HTTP_HOST'] ?? 'localhost')
                        )
                    ),
                    '/'
                );

                $url=
                    $baseUrl.
                    '/downloads/android/'.
                    rawurlencode($fileName);

                try {

                    $db->beginTransaction();

                    $q=$db->prepare("
                        INSERT INTO mucho_client_release_files
                        (
                            platform,
                            version,
                            file_name,
                            size_bytes,
                            sha256,
                            signer_sha256,
                            signature_status,
                            download_url,
                            uploaded_by
                        )
                        VALUES
                        (
                            'android',
                            :version,
                            :file,
                            :size,
                            :sha,
                            :signer,
                            :signature,
                            :url,
                            :admin
                        )
                    ");

                    $q->execute([
                        'version'=>$upload['version'],
                        'file'=>$fileName,
                        'size'=>$actual,
                        'sha'=>$sha,
                        'signer'=>$signer['sha256'],
                        'signature'=>$signer['status'],
                        'url'=>$url,
                        'admin'=>
                            (string)(
                                admin()['username']
                                ?? 'unknown'
                            )
                    ]);

                    $q=$db->prepare("
                        INSERT INTO mucho_client_releases
                        (
                            platform,
                            current_version,
                            minimum_version,
                            download_url,
                            sha256,
                            release_notes,
                            signer_sha256,
                            maintenance
                        )
                        VALUES
                        (
                            'android',
                            :current,
                            :minimum,
                            :url,
                            :sha,
                            :notes,
                            :signer,
                            0
                        )
                        ON DUPLICATE KEY UPDATE
                            current_version=
                                VALUES(current_version),
                            minimum_version=
                                VALUES(minimum_version),
                            download_url=
                                VALUES(download_url),
                            sha256=
                                VALUES(sha256),
                            release_notes=
                                VALUES(release_notes),
                            signer_sha256=
                                COALESCE(
                                    VALUES(signer_sha256),
                                    signer_sha256
                                ),
                            maintenance=0
                    ");

                    $q->execute([
                        'current'=>$upload['version'],
                        'minimum'=>$upload['minimum_version'],
                        'url'=>$url,
                        'sha'=>$sha,
                        'notes'=>$upload['release_notes'],
                        'signer'=>$signer['sha256']
                    ]);

                    $q=$db->prepare("
                        DELETE FROM mucho_client_release_uploads
                        WHERE upload_id=?
                    ");

                    $q->execute([$uploadId]);

                    $db->commit();

                } catch(Throwable $e){

                    if($db->inTransaction()){
                        $db->rollBack();
                    }

                    @unlink($final);

                    throw $e;
                }

                audit(
                    $db,
                    'client.apk_publish',
                    (string)$upload['version'],
                    [
                        'size_bytes'=>$actual,
                        'sha256'=>$sha,
                        'download_url'=>$url,
                        'signature_status'=>
                            $signer['status'],
                        'signer_sha256'=>
                            $signer['sha256']
                    ]
                );

                releaseJson([
                    'ok'=>true,

                    'release'=>[
                        'platform'=>'android',
                        'version'=>$upload['version'],
                        'minimum_version'=>
                            $upload['minimum_version'],

                        'size_bytes'=>$actual,
                        'sha256'=>$sha,

                        'signature_status'=>
                            $signer['status'],

                        'signer_sha256'=>
                            $signer['sha256'],

                        'download_url'=>$url
                    ]
                ]);
            }

        } catch(Throwable $e){

            releaseJson([
                'ok'=>false,
                'error'=>$e->getMessage()
            ],400);
        }
    }
}


/* =========================================================
   UI
========================================================= */

function renderClientReleaseUploader(
    PDO $db
): void {

    $history=$db->query("
        SELECT
            version,
            file_name,
            size_bytes,
            sha256,
            signature_status,
            download_url,
            uploaded_by,
            created_at
        FROM mucho_client_release_files
        WHERE platform='android'
        ORDER BY id DESC
        LIMIT 10
    ")->fetchAll();

    ?>

    <h2>Upload New APK</h2>

    <div class="card">

        <div class="row"
             style="justify-content:space-between">

            <div>
                <b>Mucho Android Release</b><br>
                <small>
                    Chunked upload · automatic SHA-256 ·
                    release history
                </small>
            </div>

            <span class="badge">
                MAX 650 MB
            </span>

        </div>


        <form
            id="muchoApkUpload"
            style="margin-top:18px"
        >

            <div class="boxgrid">

                <label>
                    <small>Version</small><br>

                    <input
                        name="version"
                        placeholder="1.1.0"
                        required
                        style="width:100%"
                    >
                </label>


                <label>
                    <small>Minimum supported version</small><br>

                    <input
                        name="minimum_version"
                        placeholder="1.0.0"
                        required
                        style="width:100%"
                    >
                </label>

            </div>


            <p>
                <small>APK file</small><br>

                <input
                    type="file"
                    name="apk"
                    accept=".apk,application/vnd.android.package-archive"
                    required
                    style="width:100%"
                >
            </p>


            <p>
                <small>Release notes</small>

                <textarea
                    name="release_notes"
                    placeholder="What's new in this release?"
                ></textarea>
            </p>


            <button
                type="submit"
                id="muchoApkUploadButton"
            >
                Upload & Publish APK
            </button>


            <div
                id="muchoUploadBox"
                style="display:none;margin-top:16px"
            >

                <div class="row"
                     style="justify-content:space-between">

                    <small id="muchoUploadText">
                        Preparing…
                    </small>

                    <small id="muchoUploadPercent">
                        0%
                    </small>

                </div>

                <div class="bar">
                    <i
                        id="muchoUploadBar"
                        style="width:0%"
                    ></i>
                </div>

            </div>


            <div
                id="muchoUploadResult"
                style="margin-top:14px"
            ></div>

        </form>

    </div>


    <h2>Android Release History</h2>

    <div class="table">

        <table>

            <thead>
                <tr>
                    <th>Version</th>
                    <th>Size</th>
                    <th>SHA-256</th>
                    <th>Signature</th>
                    <th>Uploaded By</th>
                    <th>Date</th>
                    <th>APK</th>
                </tr>
            </thead>

            <tbody>

            <?php if(!$history): ?>

                <tr>
                    <td colspan="7" class="muted">
                        No uploaded APK releases yet.
                    </td>
                </tr>

            <?php endif ?>


            <?php foreach($history as $r): ?>

                <tr>

                    <td>
                        <b><?=h($r['version'])?></b>
                    </td>

                    <td>
                        <?=h(
                            number_format(
                                ((int)$r['size_bytes'])
                                /1024/1024,
                                1
                            )
                        )?> MB
                    </td>

                    <td>
                        <code title="<?=h($r['sha256'])?>">
                            <?=h(
                                substr(
                                    $r['sha256'],
                                    0,
                                    12
                                )
                            )?>…
                        </code>
                    </td>

                    <td>
                        <?php if(
                            $r['signature_status']
                            ==='verified'
                        ): ?>

                            <span class="badge green">
                                VERIFIED
                            </span>

                        <?php else: ?>

                            <span class="badge">
                                UNCHECKED
                            </span>

                        <?php endif ?>
                    </td>

                    <td>
                        <?=h($r['uploaded_by'])?>
                    </td>

                    <td class="muted">
                        <?=h($r['created_at'])?>
                    </td>

                    <td>
                        <a
                            class="btn gray"
                            href="<?=h($r['download_url'])?>"
                        >
                            Download
                        </a>
                    </td>

                </tr>

            <?php endforeach ?>

            </tbody>

        </table>

    </div>


<script>
(() => {

const form =
    document.getElementById(
        'muchoApkUpload'
    );

if(!form) return;

const csrf = <?=json_encode(csrf())?>;

const button =
    document.getElementById(
        'muchoApkUploadButton'
    );

const box =
    document.getElementById(
        'muchoUploadBox'
    );

const text =
    document.getElementById(
        'muchoUploadText'
    );

const percent =
    document.getElementById(
        'muchoUploadPercent'
    );

const bar =
    document.getElementById(
        'muchoUploadBar'
    );

const result =
    document.getElementById(
        'muchoUploadResult'
    );

const endpoint =
    '/admin/?page=clientfeatures';

const CHUNK_SIZE =
    1024 * 1024;


async function post(data){

    const response =
        await fetch(
            endpoint,
            {
                method:'POST',
                body:data,
                credentials:'same-origin'
            }
        );

    let json;

    try{
        json=await response.json();
    }catch{
        throw new Error(
            'Invalid server response.'
        );
    }

    if(
        !response.ok ||
        !json.ok
    ){
        const error =
            new Error(
                json.error ||
                'Request failed.'
            );

        error.response=response;
        error.data=json;

        throw error;
    }

    return json;
}


async function sendChunk(
    uploadId,
    file,
    offset
){
    let attempts=0;

    while(attempts<4){

        attempts++;

        const end=Math.min(
            offset+CHUNK_SIZE,
            file.size
        );

        const blob=
            file.slice(
                offset,
                end
            );

        const fd=
            new FormData();

        fd.append(
            'csrf',
            csrf
        );

        fd.append(
            'action',
            'clientrelease-upload-chunk'
        );

        fd.append(
            'upload_id',
            uploadId
        );

        fd.append(
            'offset',
            String(offset)
        );

        fd.append(
            'chunk',
            blob,
            'chunk.bin'
        );

        try{

            const response =
                await fetch(
                    endpoint,
                    {
                        method:'POST',
                        body:fd,
                        credentials:'same-origin'
                    }
                );

            const data =
                await response.json();

            if(
                response.status===409 &&
                typeof data.expected_offset
                    ==='number'
            ){
                return data.expected_offset;
            }

            if(
                !response.ok ||
                !data.ok
            ){
                throw new Error(
                    data.error ||
                    'Chunk upload failed.'
                );
            }

            return Number(
                data.received_bytes
            );

        }catch(error){

            if(attempts>=4){
                throw error;
            }

            await new Promise(
                resolve =>
                    setTimeout(
                        resolve,
                        1000*attempts
                    )
            );
        }
    }
}


form.addEventListener(
    'submit',
    async event => {

        event.preventDefault();

        const file =
            form.apk.files[0];

        if(!file){
            return;
        }

        if(
            !file.name
                .toLowerCase()
                .endsWith('.apk')
        ){
            result.innerHTML=
                '<span class="bad">'+
                'Please select an APK file.'+
                '</span>';

            return;
        }

        if(
            file.size >
            650*1024*1024
        ){
            result.innerHTML=
                '<span class="bad">'+
                'APK is too large.'+
                '</span>';

            return;
        }

        button.disabled=true;
        box.style.display='block';

        result.textContent='';
        bar.style.width='0%';
        percent.textContent='0%';
        text.textContent=
            'Creating upload session…';

        let uploadId=null;

        try{

            const init =
                new FormData();

            init.append('csrf',csrf);

            init.append(
                'action',
                'clientrelease-upload-init'
            );

            init.append(
                'version',
                form.version.value
            );

            init.append(
                'minimum_version',
                form.minimum_version.value
            );

            init.append(
                'release_notes',
                form.release_notes.value
            );

            init.append(
                'original_name',
                file.name
            );

            init.append(
                'total_size',
                String(file.size)
            );

            const initData=
                await post(init);

            uploadId=
                initData.upload_id;

            let offset=0;

            while(
                offset < file.size
            ){
                text.textContent=
                    'Uploading '+
                    (
                        offset/1024/1024
                    ).toFixed(1)+
                    ' / '+
                    (
                        file.size/1024/1024
                    ).toFixed(1)+
                    ' MB';

                offset =
                    await sendChunk(
                        uploadId,
                        file,
                        offset
                    );

                const value=
                    Math.min(
                        100,
                        Math.floor(
                            offset /
                            file.size *
                            100
                        )
                    );

                bar.style.width=
                    value+'%';

                percent.textContent=
                    value+'%';
            }

            text.textContent=
                'Verifying & publishing…';

            const final =
                new FormData();

            final.append(
                'csrf',
                csrf
            );

            final.append(
                'action',
                'clientrelease-upload-finalize'
            );

            final.append(
                'upload_id',
                uploadId
            );

            const done=
                await post(final);

            bar.style.width='100%';
            percent.textContent='100%';

            text.textContent=
                'Published successfully';

            result.innerHTML=
                '<span class="ok">'+
                'Mucho GDPS '+
                done.release.version+
                ' published successfully.</span>'+
                '<br><br>'+
                '<a class="btn green" href="'+
                done.release.download_url+
                '">Download APK</a>'+
                '<br><br>'+
                '<small>SHA-256: '+
                done.release.sha256+
                '</small>';

            form.apk.value='';

            setTimeout(
                () => location.reload(),
                2500
            );

        }catch(error){

            text.textContent=
                'Upload failed';

            result.innerHTML=
                '<span class="bad">'+
                String(
                    error.message ||
                    error
                )+
                '</span>';

        }finally{

            button.disabled=false;
        }
    }
);

})();
</script>

    <?php
}
