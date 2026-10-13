package com.geode.launcher.mucho

import org.junit.Test
import org.junit.Assert.*
import java.io.File
import java.nio.file.Files

class PayloadFilesTest {
    @Test fun corruptionIsRepairedAndBadSourcePreservesOriginal() {
        val root = Files.createTempDirectory("mucho-test").toFile()
        try {
            val bytes = "verified payload".toByteArray()
            val hash = PayloadFiles.digest(bytes.inputStream())
            PayloadFiles.replace(root, "mods/client.geode", hash) { bytes.inputStream() }
            val file = File(root, "mods/client.geode")
            assertTrue(PayloadFiles.matches(file, hash))
            assertThrows(IllegalArgumentException::class.java) {
                PayloadFiles.replace(root, "mods/client.geode", hash) { "bad".byteInputStream() }
            }
            assertArrayEquals(bytes, file.readBytes())
            file.writeText("corrupted")
            assertFalse(PayloadFiles.matches(file, hash))
            PayloadFiles.replace(root, "mods/client.geode", hash) { bytes.inputStream() }
            assertTrue(PayloadFiles.matches(file, hash))
            assertEquals(listOf("client.geode"), file.parentFile!!.list()!!.toList())
        } finally { root.deleteRecursively() }
    }
    @Test fun rejectsTraversalAndSymbolicLinks() {
        val root = Files.createTempDirectory("mucho-test").toFile()
        val outside = Files.createTempDirectory("mucho-outside").toFile()
        try {
            for (path in listOf("../escape", "/absolute", "a/../b", "a\\b", "a//b")) {
                assertThrows(IllegalArgumentException::class.java) { PayloadFiles.resolve(root, path) }
            }
            Files.createSymbolicLink(File(root, "mods").toPath(), outside.toPath())
            assertThrows(IllegalArgumentException::class.java) { PayloadFiles.resolve(root, "mods/client.geode") }
        } finally { root.deleteRecursively(); outside.deleteRecursively() }
    }
}
