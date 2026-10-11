"""MuchoLoader API 1. Calls are implemented by the native loader, not a Geode mod."""
from ._api import events, ui, effects, log

__version__ = "0.1.0"
__all__ = ["events", "ui", "effects", "log"]
