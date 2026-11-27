package space.muchogdps.legacysongs;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.DialogInterface;
import android.media.AudioManager;
import android.media.MediaPlayer;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ListView;
import android.widget.TextView;
import android.widget.Toast;

import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Properties;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public final class Overlay {
    private static final String ASSET = "muchocore-legacy.properties";
    private static Panel openPanel;

    private Overlay() {}

    public static void attach(final Activity activity) {
        FrameLayout content = (FrameLayout) activity.findViewById(android.R.id.content);
        Button button = new Button(activity);
        button.setText("Custom Songs");
        FrameLayout.LayoutParams layout = new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.WRAP_CONTENT, FrameLayout.LayoutParams.WRAP_CONTENT,
                Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        layout.topMargin = (int) (30 * activity.getResources().getDisplayMetrics().density);
        content.addView(button, layout);
        button.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View view) {
                if (openPanel != null) {
                    return;
                }
                Properties properties = new Properties();
                try (InputStream stream = activity.getAssets().open(ASSET)) {
                    properties.load(stream);
                    CatalogClient client = new CatalogClient(properties.getProperty("server"),
                            "shared".equals(properties.getProperty("mode")),
                            new File(activity.getCacheDir(), "muchocore-legacy-songs"));
                    openPanel = new Panel(activity, client);
                    openPanel.show();
                } catch (IOException error) {
                    Toast.makeText(activity, "Custom Songs: " + error.getMessage(), Toast.LENGTH_LONG).show();
                }
            }
        });
    }

    public static void pause(Activity activity) {
        if (openPanel != null && openPanel.activity == activity) {
            openPanel.dialog.dismiss();
        }
    }

    private static final class Panel {
        private static final long REFRESH_MS = 120000;
        private final Activity activity;
        private final CatalogClient client;
        private final Handler ui = new Handler(Looper.getMainLooper());
        private final ExecutorService worker = Executors.newSingleThreadExecutor();
        private final AlertDialog dialog;
        private final TextView status;
        private final ListView list;
        private final Button refresh;
        private final Button next;
        private final Button stop;
        private AlertDialog levelPrompt;
        private final Runnable poll = new Runnable() {
            @Override public void run() {
                if (!closed && !busy && player == null) {
                    load(currentAfter);
                } else if (!closed) {
                    schedulePoll();
                }
            }
        };
        private List<CatalogClient.Track> tracks = Collections.emptyList();
        private int nextAfter;
        private int currentAfter;
        private boolean busy;
        private boolean closed;
        private MediaPlayer player;

        Panel(Activity activity, CatalogClient client) {
            this.activity = activity;
            this.client = client;
            LinearLayout content = new LinearLayout(activity);
            content.setOrientation(LinearLayout.VERTICAL);
            int padding = (int) (12 * activity.getResources().getDisplayMetrics().density);
            content.setPadding(padding, 0, padding, 0);
            status = new TextView(activity);
            content.addView(status);
            list = new ListView(activity);
            int listHeight = (int) (155 * activity.getResources().getDisplayMetrics().density);
            content.addView(list, new LinearLayout.LayoutParams(-1, listHeight));
            list.setOnItemClickListener(new AdapterView.OnItemClickListener() {
                @Override public void onItemClick(AdapterView<?> list, View view, int position, long id) {
                    if (!busy) {
                        preview(tracks.get(position));
                    }
                }
            });
            LinearLayout controls = new LinearLayout(activity);
            refresh = new Button(activity);
            refresh.setText("Refresh");
            refresh.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View view) { load(0); }
            });
            next = new Button(activity);
            next.setText("Next page");
            next.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View view) { load(nextAfter); }
            });
            stop = new Button(activity);
            stop.setText("Stop preview");
            stop.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View view) {
                    releasePlayer();
                    setBusy(false);
                    status.setText("Preview stopped");
                }
            });
            controls.addView(refresh);
            controls.addView(next);
            controls.addView(stop);
            content.addView(controls);
            dialog = new AlertDialog.Builder(activity)
                    .setTitle("Custom Songs")
                    .setView(content)
                    .setNeutralButton("Find by level ID", null)
                    .setNegativeButton("Close", null)
                    .create();
            dialog.setOnDismissListener(new DialogInterface.OnDismissListener() {
                @Override public void onDismiss(DialogInterface dialog) {
                    closed = true;
                    if (levelPrompt != null) {
                        levelPrompt.dismiss();
                    }
                    ui.removeCallbacks(poll);
                    worker.shutdownNow();
                    releasePlayer();
                    if (openPanel == Panel.this) {
                        openPanel = null;
                    }
                }
            });
        }

        void show() {
            dialog.show();
            dialog.getButton(DialogInterface.BUTTON_NEUTRAL).setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View view) { askForLevel(); }
            });
            stop.setEnabled(false);
            load(0);
        }

        private void setBusy(boolean value) {
            busy = value;
            refresh.setEnabled(!value);
            next.setEnabled(!value && nextAfter > 0);
            dialog.getButton(DialogInterface.BUTTON_NEUTRAL).setEnabled(!value);
        }

        private void load(final int after) {
            if (busy || closed) {
                return;
            }
            setBusy(true);
            status.setText("Loading from " + client.host());
            worker.submit(new Runnable() {
                @Override public void run() {
                    try {
                        final CatalogClient.Page page = client.catalog(after);
                        ui.post(new Runnable() {
                            @Override public void run() { showPage(page, after); }
                        });
                    } catch (Exception error) {
                        showError("Catalog: " + error.getMessage());
                    }
                }
            });
        }

        private void showPage(CatalogClient.Page page, int after) {
            if (closed) {
                return;
            }
            tracks = page.tracks;
            currentAfter = after;
            nextAfter = page.nextAfter;
            List<String> rows = new ArrayList<String>();
            for (CatalogClient.Track track : tracks) {
                rows.add(track.title + " — " + track.artist + " (#" + track.id + ")");
            }
            list.setAdapter(new ArrayAdapter<String>(activity, android.R.layout.simple_list_item_1, rows));
            status.setText(tracks.isEmpty() ? "No approved tracks on this page" : "Tap a track to download and preview it");
            setBusy(false);
            schedulePoll();
        }

        private void askForLevel() {
            ui.removeCallbacks(poll);
            final EditText input = new EditText(activity);
            input.setInputType(InputType.TYPE_CLASS_NUMBER);
            input.setSingleLine(true);
            input.setHint("Level ID");
            final AlertDialog prompt = new AlertDialog.Builder(activity)
                    .setTitle("Find a level's song")
                    .setView(input)
                    .setNegativeButton("Cancel", null)
                    .setPositiveButton("Preview", null)
                    .create();
            levelPrompt = prompt;
            prompt.setOnDismissListener(new DialogInterface.OnDismissListener() {
                @Override public void onDismiss(DialogInterface ignored) {
                    levelPrompt = null;
                    if (!closed) {
                        schedulePoll();
                    }
                }
            });
            prompt.show();
            prompt.getButton(DialogInterface.BUTTON_POSITIVE).setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View view) {
                    final int levelId;
                    try {
                        levelId = Integer.parseInt(input.getText().toString().trim());
                        if (levelId <= 0) {
                            throw new NumberFormatException();
                        }
                    } catch (NumberFormatException invalidId) {
                        input.setError("Enter a positive level ID");
                        return;
                    }
                    prompt.dismiss();
                    previewLevel(levelId);
                }
            });
        }

        private void previewLevel(final int levelId) {
            releasePlayer();
            setBusy(true);
            status.setText("Finding song for level #" + levelId);
            worker.submit(new Runnable() {
                @Override public void run() {
                    try {
                        final CatalogClient.LevelSong song = client.downloadForLevel(levelId);
                        ui.post(new Runnable() {
                            @Override public void run() {
                                if (closed) {
                                    return;
                                }
                                if (song == null) {
                                    status.setText("This level has no MuchoCore song");
                                    setBusy(false);
                                } else {
                                    play(song.file, song.track.title);
                                }
                            }
                        });
                    } catch (Exception error) {
                        showError("Level #" + levelId + ": " + error.getMessage());
                    }
                }
            });
        }

        private void preview(final CatalogClient.Track track) {
            releasePlayer();
            setBusy(true);
            status.setText("Preparing " + track.title);
            worker.submit(new Runnable() {
                @Override public void run() {
                    try {
                        final File file = client.download(track.id);
                        ui.post(new Runnable() {
                            @Override public void run() { play(file, track.title); }
                        });
                    } catch (Exception error) {
                        showError("Download: " + error.getMessage());
                    }
                }
            });
        }

        private void play(File file, final String title) {
            if (closed) {
                return;
            }
            MediaPlayer preview = new MediaPlayer();
            player = preview;
            try {
                preview.setAudioStreamType(AudioManager.STREAM_MUSIC);
                try (FileInputStream input = new FileInputStream(file)) {
                    preview.setDataSource(input.getFD());
                }
                preview.setOnPreparedListener(new MediaPlayer.OnPreparedListener() {
                    @Override public void onPrepared(MediaPlayer ready) {
                        if (!closed && player == ready) {
                            try {
                                ready.start();
                                setBusy(false);
                                status.setText("Playing " + title);
                            } catch (IllegalStateException error) {
                                releasePlayer();
                                showError("Android could not start MP3 playback");
                            }
                        }
                    }
                });
                preview.setOnCompletionListener(new MediaPlayer.OnCompletionListener() {
                    @Override public void onCompletion(MediaPlayer completed) {
                        if (player == completed) {
                            releasePlayer();
                            status.setText(title + " is saved in cache");
                        }
                    }
                });
                preview.setOnErrorListener(new MediaPlayer.OnErrorListener() {
                    @Override public boolean onError(MediaPlayer failed, int what, int extra) {
                        if (player == failed) {
                            releasePlayer();
                            showError("Android could not play the downloaded MP3");
                        }
                        return true;
                    }
                });
                preview.prepareAsync();
                stop.setEnabled(true);
            } catch (IOException | RuntimeException error) {
                releasePlayer();
                showError("Preview: " + error.getMessage());
            }
        }

        private void releasePlayer() {
            if (player != null) {
                player.release();
                player = null;
            }
            stop.setEnabled(false);
        }

        private void showError(final String message) {
            ui.post(new Runnable() {
                @Override public void run() {
                    if (!closed) {
                        setBusy(false);
                        status.setText(message);
                        schedulePoll();
                    }
                }
            });
        }

        private void schedulePoll() {
            ui.removeCallbacks(poll);
            ui.postDelayed(poll, REFRESH_MS);
        }
    }
}
