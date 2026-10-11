import contextvars
import json
import math
import re
from types import SimpleNamespace
import _mucho_native as native

_context = contextvars.ContextVar("mucho_plugin_context", default=None)
_id = re.compile(r"^[a-z][a-z0-9._-]{0,63}$")


def _current():
    ctx = _context.get()
    if ctx is None:
        raise RuntimeError("Mucho API must be called from a managed plugin callback")
    return ctx


def _text(value, limit):
    if not isinstance(value, str) or not value or len(value) > limit:
        raise ValueError(f"Expected text of 1..{limit} characters")
    return "".join(c if c >= " " and c not in "<>" else " " for c in value)


def _submit(operation, payload):
    ctx = _current()
    native.command(ctx.plugin.id, ctx.plugin.generation, ctx.scene, operation,
                   json.dumps(payload, ensure_ascii=True, allow_nan=False))


def log(message):
    ctx = _current()
    native.log(ctx.plugin.id, _text(message, 1000))


class _Events:
    def on(self, event):
        if event not in {"menu.ready", "level.start", "level.death", "level.complete"}:
            raise ValueError("Unsupported event: " + str(event))

        def register(callback):
            if not callable(callback):
                raise TypeError("Event handler must be callable")
            plugin = _current().plugin
            if sum(map(len, plugin.handlers.values())) >= 64:
                raise ValueError("Plugin handler limit reached")
            plugin.handlers.setdefault(event, []).append(callback)
            return callback
        return register


class _UI:
    def notice(self, message):
        _submit("notice", {"message": _text(message, 180)})

    def button(self, button_id, title, callback):
        if not isinstance(button_id, str) or not _id.fullmatch(button_id):
            raise ValueError("Invalid button ID")
        if not callable(callback):
            raise TypeError("Button callback must be callable")
        plugin = _current().plugin
        if len(plugin.buttons) >= 16 and button_id not in plugin.buttons:
            raise ValueError("Plugin button limit reached")
        title = _text(title, 32)
        plugin.buttons[button_id] = (title, callback)


class _Effects:
    def pulse(self, color="#A855F7", duration=0.3, opacity=90):
        if not isinstance(color, str) or not re.fullmatch(r"#[0-9A-Fa-f]{6}", color):
            raise ValueError("Color must be #RRGGBB")
        if isinstance(duration, bool) or not isinstance(duration, (int, float)) or not math.isfinite(duration) or not 0.05 <= duration <= 3:
            raise ValueError("Duration must be between 0.05 and 3 seconds")
        if type(opacity) is not int or not 0 <= opacity <= 150:
            raise ValueError("Opacity must be an integer between 0 and 150")
        _submit("pulse", {"color": color, "duration": duration, "opacity": opacity})


events = _Events()
ui = _UI()
effects = _Effects()


def _scope(plugin, scene=0):
    return _context.set(SimpleNamespace(plugin=plugin, scene=scene))
