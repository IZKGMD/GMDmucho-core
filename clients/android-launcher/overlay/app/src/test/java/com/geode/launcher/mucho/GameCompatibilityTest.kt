package com.geode.launcher.mucho

import org.junit.Assert.*
import org.junit.Test
import java.io.File
import java.nio.file.Files
import java.util.zip.ZipEntry
import java.util.zip.ZipOutputStream

class GameCompatibilityTest {
    private fun elf(abi: String): ByteArray = ByteArray(20).apply {
        this[0] = 0x7f; this[1] = 69; this[2] = 76; this[3] = 70
        this[4] = if (abi == "arm64-v8a") 2 else 1
        this[5] = 1
        this[18] = if (abi == "arm64-v8a") 183.toByte() else 40
    }

    private fun apk(root: File, name: String, entries: Map<String, ByteArray>): File {
        val file = File(root, name)
        ZipOutputStream(file.outputStream()).use { zip -> entries.forEach { (path, bytes) ->
            zip.putNextEntry(ZipEntry(path)); zip.write(bytes); zip.closeEntry()
        } }
        return file
    }

    @Test fun supportsGameVersionNamesWithoutDependingOnStoreVersionCode() {
        for (name in listOf("2.2081", "2.2.144", "2.2081 (custom)", "2.2081-mod", " 2.2081 "))
            assertTrue(name, GameCompatibility.supportedVersion(name))
        for (name in listOf(null, "", "2.206", "2.208", "2.20810", "2.2081evil", "9.2.2081"))
            assertFalse(name, GameCompatibility.supportedVersion(name))
    }

    @Test fun findsNativeGameLibrariesAcrossBaseAndSplitsWithoutPackageOrCertificateChecks() {
        val root = Files.createTempDirectory("mucho-game").toFile()
        try {
            val base = apk(root, "base.apk", mapOf("lib/arm64-v8a/libfmod.so" to elf("arm64-v8a")))
            val split = apk(root, "split.apk", mapOf("lib/arm64-v8a/libcocos2dcpp.so" to elf("arm64-v8a")))
            assertEquals(setOf("arm64-v8a"), GameCompatibility.architectures(listOf(base, split)))
            assertTrue(GameCompatibility.architectures(listOf(base)).isEmpty())
        } finally { root.deleteRecursively() }
    }

    @Test fun rejectsMissingCorruptAndMislabeledNativeLibraries() {
        val root = Files.createTempDirectory("mucho-game").toFile()
        try {
            val invalid = apk(root, "invalid.apk", mapOf(
                "lib/arm64-v8a/libfmod.so" to elf("armeabi-v7a"),
                "lib/arm64-v8a/libcocos2dcpp.so" to "not ELF".toByteArray()))
            assertTrue(GameCompatibility.architectures(listOf(invalid, File(root, "missing.apk"))).isEmpty())
        } finally { root.deleteRecursively() }
    }

    @Test fun routesEachLibraryToItsActualSplitAndCopiesCompressedEntries() {
        val root = Files.createTempDirectory("mucho-game").toFile()
        try {
            val fmod = elf("arm64-v8a") + byteArrayOf(1, 2, 3)
            val cocos = elf("arm64-v8a") + byteArrayOf(4, 5, 6)
            val base = apk(root, "base.apk", mapOf("lib/arm64-v8a/libfmod.so" to fmod))
            val split = apk(root, "renamed-native.apk", mapOf("lib/arm64-v8a/libcocos2dcpp.so" to cocos))
            val sources = listOf(base, split)
            val fmodPath = GameCompatibility.libraryPath(sources, null, "arm64-v8a", "libfmod.so")!!
            val cocosPath = GameCompatibility.libraryPath(sources, null, "arm64-v8a", "libcocos2dcpp.so")!!
            assertTrue(fmodPath.startsWith(base.absolutePath + "!/"))
            assertTrue(cocosPath.startsWith(split.absolutePath + "!/"))
            val copy = File(root, "copy.so")
            GameCompatibility.copyLibrary(cocosPath, copy)
            assertArrayEquals(cocos, copy.readBytes())
            GameCompatibility.copyLibrary(fmodPath, copy)
            assertArrayEquals(fmod, copy.readBytes())
            assertNull(GameCompatibility.libraryPath(sources, null, "armeabi-v7a", "libfmod.so"))
        } finally { root.deleteRecursively() }
    }

    @Test fun distinguishesArm32AndArm64AndSupportsExtractedLibraries() {
        val root = Files.createTempDirectory("mucho-game").toFile()
        try {
            val combined = apk(root, "both.apk", listOf("armeabi-v7a", "arm64-v8a").flatMap { abi ->
                listOf("libfmod.so", "libcocos2dcpp.so").map { "lib/$abi/$it" to elf(abi) }
            }.toMap())
            assertEquals(setOf("armeabi-v7a", "arm64-v8a"), GameCompatibility.architectures(listOf(combined)))
            for (name in listOf("libfmod.so", "libcocos2dcpp.so")) File(root, name).writeBytes(elf("armeabi-v7a"))
            assertEquals(setOf("armeabi-v7a"), GameCompatibility.architectures(emptyList(), root))
        } finally { root.deleteRecursively() }
    }
}
