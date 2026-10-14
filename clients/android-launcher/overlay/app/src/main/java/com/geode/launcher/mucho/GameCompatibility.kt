package com.geode.launcher.mucho

import java.io.File
import java.io.InputStream
import java.util.zip.ZipFile

/** Compatibility is based on game metadata and native payload, not its signer or installer. */
object GameCompatibility {
    private val version = Regex("^(?:2\\.2081|2\\.2\\.144)(?:$|[-+\\s(])")
    private val libraries = setOf("libcocos2dcpp.so", "libfmod.so")

    fun supportedVersion(name: String?): Boolean = name != null && version.containsMatchIn(name.trim())

    private fun architecture(stream: InputStream): String? {
        val header = ByteArray(20)
        var offset = 0
        while (offset < header.size) {
            val count = stream.read(header, offset, header.size - offset)
            if (count <= 0) return null
            offset += count
        }
        if (header[0] != 0x7f.toByte() || header[1] != 69.toByte() ||
            header[2] != 76.toByte() || header[3] != 70.toByte() || header[5] != 1.toByte()) return null
        val machine = (header[18].toInt() and 255) or ((header[19].toInt() and 255) shl 8)
        return when {
            header[4] == 1.toByte() && machine == 40 -> "armeabi-v7a"
            header[4] == 2.toByte() && machine == 183 -> "arm64-v8a"
            else -> null
        }
    }

    fun architectures(apks: List<File>, nativeDirectory: File? = null): Set<String> {
        val found = mutableMapOf<String, MutableSet<String>>()
        for (apk in apks) {
            runCatching {
                ZipFile(apk).use { zip ->
                    for (abi in listOf("arm64-v8a", "armeabi-v7a")) for (library in libraries) {
                        val entry = zip.getEntry("lib/$abi/$library") ?: continue
                        val actual = zip.getInputStream(entry).use { architecture(it) }
                        if (actual == abi) found.getOrPut(abi) { mutableSetOf() }.add(library)
                    }
                }
            }
        }
        if (nativeDirectory != null) for (library in libraries) {
            val file = File(nativeDirectory, library)
            val abi = runCatching { file.inputStream().use { architecture(it) } }.getOrNull() ?: continue
            found.getOrPut(abi) { mutableSetOf() }.add(library)
        }
        return found.filterValues { it.containsAll(libraries) }.keys.toSet()
    }
}
