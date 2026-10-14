package com.geode.launcher.mucho

import android.content.Context
import android.content.Intent
import com.geode.launcher.utils.Constants
import com.geode.launcher.utils.GamePackageUtils
import com.geode.launcher.utils.LaunchUtils
import java.io.File

object MuchoGame {
    data class InstalledGame(val packageName: String, val label: String, val version: String,
                             val architectures: Set<String>) {
        val description: String get() = "$label $version • $packageName"
    }
    private const val PREFERENCES = "mucho-game"
    private const val PACKAGE = "selected-package"

    @Suppress("DEPRECATION")
    private fun inspect(context: Context, name: String): InstalledGame? = runCatching {
        val info = context.packageManager.getPackageInfo(name, 0)
        val app = info.applicationInfo ?: return null
        if (!app.enabled || name == context.packageName) return null
        val sources = listOf(app.sourceDir) + (app.splitSourceDirs?.toList() ?: emptyList())
        val abis = GameCompatibility.architectures(sources.map { File(it) }, File(app.nativeLibraryDir))
        if (abis.isEmpty()) return null
        InstalledGame(name, app.loadLabel(context.packageManager).toString(), info.versionName ?: "?", abis)
    }.getOrNull()

    @Suppress("DEPRECATION")
    fun installed(context: Context): List<InstalledGame> {
        val launcher = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
        val names = context.packageManager.queryIntentActivities(launcher, 0)
            .map { it.activityInfo.packageName } + Constants.PACKAGE_NAME
        return names.distinct().mapNotNull { inspect(context, it) }
            .sortedWith(compareBy<InstalledGame> { !GameCompatibility.supportedVersion(it.version) }
                .thenBy { it.label }.thenBy { it.packageName })
    }

    fun select(context: Context, name: String) {
        check(inspect(context, name) != null) { "Игра удалена или в ней отсутствуют библиотеки GD." }
        check(context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE).edit()
            .putString(PACKAGE, name).commit()) { "Не удалось сохранить выбор игры." }
        GamePackageUtils.usePackage(name)
    }

    fun selectedDescription(context: Context): String? {
        val name = context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE).getString(PACKAGE, null)
            ?: return null
        return inspect(context, name)?.description ?: "Выбранная игра удалена: $name. Нажми «Выбрать игру»."
    }

    fun require(context: Context): InstalledGame {
        val name = context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE).getString(PACKAGE, null)
        val selected = if (name != null) {
            inspect(context, name) ?: error("Выбранная игра удалена. Нажми «Выбрать игру».")
        } else {
            val games = installed(context)
            val compatible = games.filter { GameCompatibility.supportedVersion(it.version) }
            val matching = compatible.filter { LaunchUtils.applicationArchitecture in it.architectures }
            val choices = matching.ifEmpty { compatible.ifEmpty { games } }
            choices.firstOrNull { it.packageName == Constants.PACKAGE_NAME }
                ?: choices.singleOrNull()
                ?: error(if (games.isEmpty()) "GD не найдена. Установи Geometry Dash 2.2081 и нажми «Выбрать игру»."
                    else "Найдено несколько игр. Нажми «Выбрать игру».")
        }
        GamePackageUtils.usePackage(selected.packageName)
        check(GameCompatibility.supportedVersion(selected.version)) {
            "Нужна GD 2.2081 (Android 2.2.144). Выбрана ${selected.version}. Нажми «Выбрать игру»."
        }
        val abi = LaunchUtils.applicationArchitecture
        check(abi in selected.architectures) {
            if (abi == "arm64-v8a" && "armeabi-v7a" in selected.architectures)
                "В этой GD только ARM32. Установи MuchoLauncher Android32 поверх текущего лаунчера."
            else "Архитектуры не совпадают: лаунчер $abi, GD ${selected.architectures.joinToString()}."
        }
        return selected
    }
}
