                ]);
            }


            /* CHUNK */

            if (
                $action===
                'clientrelease-upload-chunk'
            ) {
                $uploadId=trim(
                    (string)(
                        $_POST['upload_id']
                        ?? ''
                    )
                );

                $offset=(int)(
                    $_POST['offset'] ?? -1
                );

                if (!preg_match('/^[a-f0-9]{48}$/', $uploadId)) {
                    throw new RuntimeException('Invalid upload ID.');
                }

                if (
                    !isset($_FILES['chunk']) ||
                    ($_FILES['chunk']['error'] ?? -1) !== UPLOAD_ERR_OK
                ) {
                    throw new RuntimeException('Chunk missing.');
                }

                $chunkSize=(int)(
                    $_FILES['chunk']['size'] ?? 0
                );

                if ($chunkSize<=0 || $chunkSize>(2*1024*1024)) {
                    throw new RuntimeException('Invalid chunk size.');
                }

                $tmp=(string)($_FILES['chunk']['tmp_name'] ?? '');
                if ($tmp==='' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Invalid uploaded chunk.');
                }

                $db->beginTransaction();

                try {
                    $q=$db->prepare("
                        SELECT *
                        FROM mucho_client_release_uploads
                        WHERE upload_id=?
                          AND admin_user_id=?
                          AND expires_at > NOW()
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $q->execute([$uploadId,$adminId]);
                    $upload=$q->fetch();

                    if(!$upload){
                        throw new RuntimeException('Upload session not found or expired.');
                    }

                    $path=MUCHO_RELEASE_TMP.'/'.basename((string)$upload['temp_name']);
                    clearstatcache(true,$path);
                    $actual=is_file($path) ? (int)filesize($path) : 0;
                    $recorded=(int)$upload['received_bytes'];

                    if($actual!==$recorded){
                        throw new RuntimeException('Upload state does not match the temporary file.');
                    }

                    if($offset!==$actual){
                        $db->rollBack();
                        releaseJson([
                            'ok'=>false,
                            'error'=>'offset_mismatch',
                            'expected_offset'=>$actual
                        ],409);
                    }

                    $total=(int)$upload['total_size'];
                    if($actual+$chunkSize>$total){
                        throw new RuntimeException('Upload exceeds expected size.');
                    }

                    $data=file_get_contents($tmp);
                    if($data===false || strlen($data)!==$chunkSize){
                        throw new RuntimeException('Cannot read chunk.');
                    }

                    $written=file_put_contents(
                        $path,
                        $data,
                        FILE_APPEND | LOCK_EX
                    );

                    if($written===false || $written!==strlen($data)){
                        throw new RuntimeException('Cannot write chunk.');
                    }

                    $received=$actual+$written;
                    $q=$db->prepare("
                        UPDATE mucho_client_release_uploads
                        SET received_bytes=?,
                            expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR)
                        WHERE upload_id=? AND admin_user_id=?
                    ");
                    $q->execute([$received,$uploadId,$adminId]);

                    if($q->rowCount()!==1){
                        throw new RuntimeException('Upload session update failed.');
                    }

                    $db->commit();
                } catch(Throwable $e) {
                    if($db->inTransaction()){
                        $db->rollBack();
                    }
                    throw $e;
                }

                releaseJson([
                    'ok'=>true,
                    'received_bytes'=>$received,
                    'total_size'=>(int)$upload['total_size']
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
