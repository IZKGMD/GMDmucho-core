class MainActivity : ComponentActivity() {
    private var status by mutableStateOf("Проверяю встроенный клиент…")
    private var busy by mutableStateOf(false)
    private var verified by mutableStateOf(false)
    private var game by mutableStateOf("Нужна установленная Geometry Dash 2.2081")
    private val exportBackup = registerForActivityResult(
        androidx.activity.result.contract.ActivityResultContracts.CreateDocument("application/zip")
    ) { uri ->
        if (uri != null) androidx.lifecycle.lifecycleScope.launch {
            busy = true
            try {
                kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    contentResolver.openOutputStream(uri)?.use { stream ->
                        java.util.zip.ZipOutputStream(stream).use { zip ->
                            val root = com.geode.launcher.utils.LaunchUtils.getSaveDirectory(this@MainActivity)
                            if (root.exists()) root.walkTopDown().filter { it.isFile }.forEach { file ->
                                check(file.canonicalPath.startsWith(root.canonicalPath + java.io.File.separator))
                                zip.putNextEntry(java.util.zip.ZipEntry(file.relativeTo(root).invariantSeparatorsPath))
                                file.inputStream().use { it.copyTo(zip) }
                                zip.closeEntry()
                            }
                        }
                    } ?: error("Не удалось открыть файл для записи")
                }
                status = "Резервная копия сохранений экспортирована."
            } catch (e: Exception) { status = e.message ?: "Не удалось сохранить резервную копию" }
            finally { busy = false }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            com.geode.launcher.mucho.MuchoScreen(status, busy, verified, game,
                onCheck = { repair, play -> checkClient(repair, play) },
                onBackup = { exportBackup.launch("MuchoLauncher-saves.zip") })
        }
        if (intent.hasExtra(com.geode.launcher.utils.LaunchUtils.LAUNCHER_KEY_RETURN_MESSAGE)) {
            status = intent.getStringExtra(com.geode.launcher.utils.LaunchUtils.LAUNCHER_KEY_RETURN_MESSAGE) ?: "Ошибка запуска"
        } else checkClient(true, false)
    }

    private fun checkClient(repair: Boolean, play: Boolean) {
        if (busy) return
        androidx.lifecycle.lifecycleScope.launch {
            busy = true
            verified = false
            status = if (repair) "Проверка и восстановление клиента…" else "Проверка SHA-256…"
            try {
                val report = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    val report = com.geode.launcher.mucho.MuchoIntegrity.prepare(this@MainActivity, repair)
                    com.geode.launcher.mucho.MuchoIntegrity.requireGame(this@MainActivity)
                    report
                }
                game = "Geometry Dash 2.2081 • ${com.geode.launcher.utils.LaunchUtils.applicationArchitecture}"
                verified = true
                status = "Проверено файлов: ${report.checked}. " +
                    if (report.repaired > 0) "Восстановлено: ${report.repaired}." else "Клиент готов к запуску."
                if (play) {
                    com.geode.launcher.utils.GeodeUtils.clearLaunchArguments()
                    com.geode.launcher.utils.PreferenceUtils.get(this@MainActivity)
                        .setString(com.geode.launcher.utils.PreferenceUtils.Key.LAUNCH_ARGUMENTS, null)
                    startActivity(Intent(this@MainActivity, GeometryDashActivity::class.java))
                }
            } catch (e: Exception) { status = e.message ?: "Ошибка проверки клиента" }
            finally { busy = false }
        }
    }
}

