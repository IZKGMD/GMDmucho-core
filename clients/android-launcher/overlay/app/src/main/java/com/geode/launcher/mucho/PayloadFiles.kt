package com.geode.launcher.mucho

import java.io.File
import java.io.FileOutputStream
import java.io.InputStream
import java.security.MessageDigest
import java.util.UUID

/** Only app-owned payload files; saves and third-party mods are never repaired. */
object PayloadFiles {
    fun digest(input: InputStream): String = input.use {
        val digest = MessageDigest.getInstance("SHA-256")
        val buffer = ByteArray(65536)
        while (true) {
            val count = it.read(buffer)
            if (count < 0) break
            digest.update(buffer, 0, count)
        }
        digest.digest().joinToString("") { byte -> "%02x".format(byte.toInt() and 255) }
    }

    fun resolve(root: File, path: String): File {
        require(path.isNotBlank() && !path.startsWith('/') && '\\' !in path)
        require(path.split('/').none { it.isEmpty() || it == "." || it == ".." })
        val base = root.canonicalFile
        val file = File(base, path)
        require(file.canonicalFile == file.absoluteFile) { "Небезопасный путь: $path" }
        require(file.canonicalPath.startsWith(base.path + File.separator))
        return file
    }

    fun matches(file: File, expected: String): Boolean =
        file.isFile && digest(file.inputStream()) == expected

    fun replace(root: File, path: String, expected: String, source: () -> InputStream) {
        val target = resolve(root, path)
        require(target.parentFile!!.mkdirs() || target.parentFile!!.isDirectory)
        val staging = File(target.parentFile, ".mucho-${UUID.randomUUID()}.tmp")
        try {
            FileOutputStream(staging).use { output ->
                source().use { it.copyTo(output) }
                output.fd.sync()
            }
            require(matches(staging, expected)) { "Контрольная сумма не совпала: $path" }
            resolve(root, path) // Recheck after writing, before replacing.
            check(staging.renameTo(target)) { "Не удалось восстановить: $path" }
        } finally {
            staging.delete()
        }
    }
}
