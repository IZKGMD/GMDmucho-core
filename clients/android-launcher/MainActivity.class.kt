class MainActivity : ComponentActivity() {
    private var status by mutableStateOf("Проверяю встроенный клиент…")
    private var busy by mutableStateOf(false)
    private var verified by mutableStateOf(false)
    private var game by mutableStateOf("Нужна установленная Geometry Dash 2.2081")
    private var games by mutableStateOf<List<com.geode.launcher.mucho.MuchoGame.InstalledGame>?>(null)
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
                onBackup = { exportBackup.launch("MuchoLauncher-saves.zip") },
                onChooseGame = { findGames() }, games = games,
                onDismissGames = { games = null }, onSelectGame = { selected ->
                    games = null
                    lifecycleScope.launch {
                        busy = true
                        try {
                            kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                                com.geode.launcher.mucho.MuchoGame.select(this@MainActivity, selected.packageName)
                            }
                            game = selected.description
                            verified = false
                        } catch (e: Exception) { status = e.message ?: "Ошибка выбора игры" }
                        finally { busy = false }
                        checkClient(true, false)
                    }
                })
        }
        if (intent.hasExtra(com.geode.launcher.utils.LaunchUtils.LAUNCHER_KEY_RETURN_MESSAGE)) {
            status = intent.getStringExtra(com.geode.launcher.utils.LaunchUtils.LAUNCHER_KEY_RETURN_MESSAGE) ?: "Ошибка запуска"
        } else checkClient(true, false)
    }

    private fun findGames() {
        if (busy) return
        lifecycleScope.launch {
            busy = true
            try {
                games = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    com.geode.launcher.mucho.MuchoGame.installed(this@MainActivity)
                }
            } catch (e: Exception) { status = e.message ?: "Ошибка поиска игр" }
            finally { busy = false }
        }
    }

    private fun checkClient(repair: Boolean, play: Boolean) {
        if (busy) return
        androidx.lifecycle.lifecycleScope.launch {
            busy = true
            verified = false
            status = if (repair) "Проверка и восстановление клиента…" else "Проверка SHA-256…"
            try {
                val description = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    com.geode.launcher.mucho.MuchoGame.selectedDescription(this@MainActivity)
                }
                if (description != null) game = description
                val (report, selected) = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    val report = com.geode.launcher.mucho.MuchoIntegrity.prepare(this@MainActivity, repair)
                    val selected = com.geode.launcher.mucho.MuchoIntegrity.requireGame(this@MainActivity)
                    report to selected
                }
                game = selected.description + " • ${com.geode.launcher.utils.LaunchUtils.applicationArchitecture}"
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

