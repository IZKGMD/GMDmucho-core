package com.geode.launcher.mucho

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.geode.launcher.BuildConfig
import com.geode.launcher.R
import com.geode.launcher.preferences.CrashLogsActivity
import com.geode.launcher.preferences.GeodeLogsListingActivity
import com.geode.launcher.utils.LaunchUtils
import com.geode.launcher.utils.PreferenceUtils

private val Background = Color(0xFF090A10)
private val SurfaceColor = Color(0xFF11131D)
private val Border = Color(0xFF2B2E40)
private val Accent = Color(0xFFB4A0FF)
private val Muted = Color(0xFFAAAEC4)
private val White = Color(0xFFF4F3FF)
private val Green = Color(0xFFB7EF76)

@Composable
private fun Panel(content: @Composable ColumnScope.() -> Unit) {
    Surface(color = SurfaceColor, shape = RoundedCornerShape(22.dp), border = BorderStroke(1.dp, Border)) {
        Column(Modifier.fillMaxWidth().padding(22.dp), verticalArrangement = Arrangement.spacedBy(14.dp), content = content)
    }
}

@Composable
fun MuchoScreen(status: String, busy: Boolean, verified: Boolean, game: String,
                onCheck: (Boolean, Boolean) -> Unit, onBackup: () -> Unit,
                onChooseGame: () -> Unit, games: List<MuchoGame.InstalledGame>?,
                onDismissGames: () -> Unit, onSelectGame: (MuchoGame.InstalledGame) -> Unit) {
    val context = LocalContext.current
    var tab by remember { mutableIntStateOf(0) }
    val openUrl: (String) -> Unit = { url ->
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
    }
    MaterialTheme(colorScheme = darkColorScheme(primary = Accent, onPrimary = Background,
        background = Background, surface = SurfaceColor, onSurface = White, onBackground = White)) {
        if (games != null) AlertDialog(onDismissRequest = onDismissGames,
            title = { Text("Выбери установленную GD") },
            text = {
                if (games.isEmpty()) Text("Не нашёл GD с библиотеками игры. Установи GD 2.2081 и повтори поиск.")
                else LazyColumn(Modifier.heightIn(max = 360.dp)) {
                    items(games, key = { it.packageName }) { installed ->
                        TextButton(onClick = { onSelectGame(installed) }, modifier = Modifier.fillMaxWidth()) {
                            Column(Modifier.fillMaxWidth()) {
                                Text(installed.label + " " + installed.version, fontWeight = FontWeight.Bold)
                                Text(installed.packageName, fontSize = 11.sp)
                                Text(installed.architectures.joinToString(), fontSize = 11.sp, color = Muted)
                            }
                        }
                    }
                }
            }, confirmButton = { TextButton(onClick = onDismissGames) { Text("Закрыть") } })
        Surface(Modifier.fillMaxSize(), color = Background) {
            Column(Modifier.safeDrawingPadding()) {
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).fillMaxWidth()
                    .widthIn(max = 720.dp).padding(horizontal = 22.dp, vertical = 24.dp),
                    verticalArrangement = Arrangement.spacedBy(18.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Surface(color = Accent, shape = RoundedCornerShape(14.dp)) {
                            Text("M", Modifier.padding(horizontal = 15.dp, vertical = 9.dp), color = Background,
                                fontSize = 26.sp, fontWeight = FontWeight.Black)
                        }
                        Column(Modifier.padding(start = 12.dp).weight(1f)) {
                            Text("MUCHO", fontWeight = FontWeight.Bold, letterSpacing = 3.sp, fontSize = 19.sp)
                            Text("LAUNCHER / ANDROID", color = Muted, fontSize = 10.sp, letterSpacing = 1.sp)
                        }
                        Text("${BuildConfig.VERSION_NAME}", color = Accent, fontSize = 12.sp)
                    }
                    when (tab) {
                        0 -> {
                            Column(Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(Color(0xFF272044), SurfaceColor)),
                                RoundedCornerShape(24.dp)).padding(24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                                Text("ТВОЯ ВСЕЛЕННАЯ", color = Accent, letterSpacing = 2.sp, fontSize = 11.sp)
                                Text("Играй. Создавай.\nБудь Mucho.", fontSize = 32.sp, lineHeight = 38.sp, fontWeight = FontWeight.Bold)
                                Text("MuchoClient и загрузчик уже внутри.\nОдин лаунчер — вся твоя игра.", color = Muted, fontSize = 14.sp)
                                Button(onClick = { onCheck(true, true) }, enabled = !busy,
                                    modifier = Modifier.fillMaxWidth().height(54.dp), shape = RoundedCornerShape(14.dp)) {
                                    Text(if (busy) "Подготовка…" else "▶  Играть", fontWeight = FontWeight.Bold, fontSize = 16.sp)
                                }
                            }
                            Panel {
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                    Text("Готовность клиента", fontWeight = FontWeight.SemiBold)
                                    Text(if (verified) "ПРОВЕРЕНО" else "ПРОВЕРКА", color = if (verified) Green else Accent, fontSize = 10.sp)
                                }
                                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
                                Text(status, color = if (verified) Green else Muted, fontSize = 13.sp)
                                Text(game, color = Muted, fontSize = 12.sp)
                                OutlinedButton(onClick = onChooseGame, enabled = !busy,
                                    modifier = Modifier.fillMaxWidth()) { Text("Выбрать игру") }
                                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                    OutlinedButton(onClick = { onCheck(false, false) }, enabled = !busy, modifier = Modifier.weight(1f)) { Text("Проверить") }
                                    OutlinedButton(onClick = { onCheck(true, false) }, enabled = !busy, modifier = Modifier.weight(1f)) { Text("Восстановить") }
                                }
                            }
                            Panel {
                                Text("CREATOR HUB", color = Accent, fontSize = 11.sp, letterSpacing = 2.sp)
                                Text("Твой следующий большой уровень", fontWeight = FontWeight.Bold, fontSize = 20.sp)
                                Text("Заявки на оценку, история ревью и очередь креаторов — в меню MuchoClient. Нужен сервер с включённым Creator Hub.", color = Muted, fontSize = 13.sp)
                            }
                        }
                        1 -> {
                            Text("Твоя библиотека", fontSize = 28.sp, fontWeight = FontWeight.Bold)
                            Panel {
                                Text("MuchoClient 1.1.0", fontWeight = FontWeight.Bold, fontSize = 20.sp)
                                Text("Смайлики • социальный профиль • кланы\nDaily Quests • Creator Hub", color = Muted)
                                Text("Встроен в APK. Восстанавливается из проверенной копии.", color = Accent, fontSize = 13.sp)
                                Button(onClick = { onCheck(true, false) }, enabled = !busy) { Text("Проверить и восстановить") }
                            }
                            Panel {
                                Text("Сохранения", fontWeight = FontWeight.Bold, fontSize = 20.sp)
                                Text("Игра в MuchoLauncher использует отдельные сохранения. Сделай резервную копию перед удалением приложения.", color = Muted, fontSize = 13.sp)
                                OutlinedButton(onClick = onBackup, enabled = !busy) { Text("Экспортировать ZIP") }
                            }
                            Panel {
                                Text("Диагностика", fontWeight = FontWeight.Bold)
                                Text("Логи помогают понять причину вылета на конкретном телефоне.", color = Muted, fontSize = 13.sp)
                                TextButton(onClick = { context.startActivity(Intent(context, GeodeLogsListingActivity::class.java)) }) { Text("Логи загрузчика") }
                                TextButton(onClick = { context.startActivity(Intent(context, CrashLogsActivity::class.java)) }) { Text("Отчёты о вылетах") }
                            }
                        }
                        2 -> {
                            Text("Создавай больше", fontSize = 28.sp, fontWeight = FontWeight.Bold)
                            Panel {
                                Text("MuchoCore", color = Accent, letterSpacing = 2.sp)
                                Text("Твой профиль. Твои уровни.\nТвоя команда.", fontWeight = FontWeight.Bold, fontSize = 24.sp)
                                Text("Социальный профиль, реакции на достижения и приглашения доступны внутри клиента.", color = Muted)
                                Button(onClick = { openUrl("https://muchogdps.space") }) { Text("Открыть MuchoCore ↗") }
                            }
                            Panel {
                                Text("Creator Hub", fontWeight = FontWeight.Bold, fontSize = 20.sp)
                                Text("Открой MuchoClient в игре → Creator Hub. Отправляй уровни на проверку и следи за результатом.", color = Muted)
                                Button(onClick = { onCheck(true, true) }, enabled = !busy) { Text("Перейти в игру") }
                            }
                        }
                        else -> {
                            Text("Под тебя", fontSize = 28.sp, fontWeight = FontWeight.Bold)
                            Panel {
                                Text("Частота кадров", fontWeight = FontWeight.Bold)
                                val preferences = remember { PreferenceUtils.get(context) }
                                var fps by remember { mutableIntStateOf(preferences.getInt(PreferenceUtils.Key.LIMIT_FRAME_RATE)) }
                                listOf(0, 30, 60, 120).forEach { value ->
                                    TextButton(onClick = { preferences.setInt(PreferenceUtils.Key.LIMIT_FRAME_RATE, value); fps = value }) {
                                        Text("${if (fps == value) "●" else "○"}  ${if (value == 0) "Без ограничения" else "$value FPS"}")
                                    }
                                }
                                Text("Применится при следующем запуске игры.", color = Muted, fontSize = 12.sp)
                            }
                            Panel {
                                Text("Geometry Dash 2.2081", fontWeight = FontWeight.Bold)
                                Text(game, color = Muted, fontSize = 12.sp)
                                OutlinedButton(onClick = onChooseGame, enabled = !busy) { Text("Выбрать игру") }
                                Text("Поддерживает установленную GD вне магазина и с другим именем пакета. Версия игры должна быть совместима с загрузчиком.", color = Muted, fontSize = 12.sp)
                                Text("Android 6+ • ARM32 / ARM64\nЗагрузчик Geode 5.10.1 • MuchoClient 1.1.0", color = Muted, fontSize = 13.sp)
                                Text("MuchoLauncher создан на основе открытого Android Launcher Geode (Boost Software License).", color = Muted, fontSize = 12.sp)
                                TextButton(onClick = { openUrl("https://github.com/geode-sdk/android-launcher/tree/1.8.0") }) { Text("Исходная основа ↗") }
                                TextButton(onClick = { openUrl("https://play.google.com/store/apps/details?id=com.robtopx.geometryjump") }) { Text("Установить Geometry Dash ↗") }
                            }
                        }
                    }
                    Text("MUCHOCORE  /  PLAY YOUR WAY", color = Muted, fontSize = 10.sp, letterSpacing = 1.sp)
                }
                NavigationBar(containerColor = SurfaceColor) {
                    listOf("Старт" to "▶", "Клиент" to "◇", "Mucho" to "M", "Настройки" to "⚙").forEachIndexed { index, item ->
                        NavigationBarItem(selected = tab == index, onClick = { tab = index },
                            icon = { Text(item.second, fontSize = 19.sp, fontWeight = FontWeight.Bold) },
                            label = { Text(item.first, fontSize = 11.sp) })
                    }
                }
            }
        }
    }
}
