from mucho import events, ui, effects, log


def hello():
    ui.notice("Python called native C++ inside MuchoLoader!")


ui.button("hello", "Say hello", hello)


@events.on("level.start")
def on_start(event):
    log(f"Level started: {event.get('level_id', 0)}")
    effects.pulse("#A855F7", duration=0.25, opacity=50)


@events.on("level.complete")
def on_complete(event):
    ui.notice("Level complete! Sent by a Python plugin.")
