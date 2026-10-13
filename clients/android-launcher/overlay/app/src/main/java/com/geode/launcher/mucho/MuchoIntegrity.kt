package com.geode.launcher.mucho

import android.content.Context
import com.geode.launcher.utils.GamePackageUtils
import com.geode.launcher.utils.LaunchUtils
import org.json.JSONObject
import java.io.File
import java.util.zip.ZipFile

object MuchoIntegrity {
    const val CLIENT_ID = "izkgmd.muchoclient"
    data class Report(val repaired: Int, val checked: Int)

    @Synchronized
    fun prepare(context: Context, repair: Boolean): Report {
        val root = LaunchUtils.getBaseDirectory(context)
        check(root.mkdirs() || root.isDirectory)
        val manifest = context.assets.open("mucho/payload.json").bufferedReader().use { JSONObject(it.readText()) }
        val loaders = manifest.getJSONObject("loaders")
        val loader = File(context.applicationInfo.nativeLibraryDir, "libgeode.so")
        check(PayloadFiles.matches(loader, loaders.getString(LaunchUtils.applicationArchitecture))) {
            "Загрузчик повреждён. Переустанови APK MuchoLauncher."
        }
        val files = manifest.getJSONArray("files")
        var repaired = 0
        for (i in 0 until files.length()) {
            val entry = files.getJSONObject(i)
            val path = entry.getString("path")
            val target = PayloadFiles.resolve(root, path)
            if (!PayloadFiles.matches(target, entry.getString("sha256"))) {
                check(repair) { "Требуется восстановление: $path" }
                PayloadFiles.replace(root, path, entry.getString("sha256")) {
                    context.assets.open(entry.getString("asset"))
                }
                repaired++
            }
        }
        // A second package with the same ID could shadow the mandatory client.
        File(root, "game/geode/mods").listFiles()?.filter {
            it.extension == "geode" && it.name != "$CLIENT_ID.geode"
        }?.forEach { file ->
            val id = runCatching {
                ZipFile(file).use { zip ->
                    zip.getInputStream(zip.getEntry("mod.json")).bufferedReader().use {
                        JSONObject(it.readText()).getString("id")
                    }
                }
            }.getOrNull()
            check(id != CLIENT_ID) { "Дубликат MuchoClient: ${file.name}. Убери его из папки mods." }
        }
        val savedPath = "save/geode/mods/geode.loader/saved.json"
        val savedFile = PayloadFiles.resolve(root, savedPath)
        if (savedFile.isFile) {
            val saved = JSONObject(savedFile.readText())
            val key = "should-load-$CLIENT_ID"
            if (!saved.optBoolean(key, true)) {
                check(repair) { "MuchoClient отключён. Нажми Восстановить." }
                saved.put(key, true)
                val bytes = saved.toString(2).toByteArray()
                PayloadFiles.replace(root, savedPath, PayloadFiles.digest(bytes.inputStream())) { bytes.inputStream() }
            }
        }
        return Report(repaired, files.length() + 1)
    }

    fun requireGame(context: Context) {
        check(GamePackageUtils.isGameInstalled(context.packageManager)) {
            "Установи Geometry Dash из Google Play или Amazon, затем вернись сюда."
        }
        check(GamePackageUtils.getGameVersionCode(context.packageManager) == 41L) {
            "Эта сборка поддерживает Geometry Dash 2.2081. Установлена ${GamePackageUtils.getUnifiedVersionName(context.packageManager)}."
        }
        check(GamePackageUtils.identifyGameLegitimacy(context.packageManager)) {
            "Не удалось подтвердить подпись Geometry Dash. Нужна оригинальная игра."
        }
    }
}
