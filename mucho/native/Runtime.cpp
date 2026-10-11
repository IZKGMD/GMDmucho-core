#define PY_SSIZE_T_CLEAN
#include <Python.h>
#include "Runtime.hpp"
#include <algorithm>
#include <stdexcept>

namespace {
std::atomic<mucho::Runtime*> activeRuntime{nullptr};
bool validOwner(std::string const& owner) {
    return !owner.empty() && owner.size() <= 64 && std::all_of(owner.begin(), owner.end(), [](char c) {
        return (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '.' || c == '-' || c == '_';
    });
}
PyObject* command(PyObject*, PyObject* args) {
    const char *owner, *operation, *payload;
    unsigned long long generation, scene;
    if (!PyArg_ParseTuple(args, "sKKss", &owner, &generation, &scene, &operation, &payload)) return nullptr;
    auto runtime = activeRuntime.load();
    if (!runtime) return PyErr_Format(PyExc_RuntimeError, "Mucho runtime is unavailable");
    if (!runtime->submit({owner, operation, payload, generation, scene}))
        return PyErr_Format(PyExc_RuntimeError, "Command rejected (inactive plugin, invalid command or full queue)");
    Py_RETURN_NONE;
}
PyObject* activate(PyObject*, PyObject* args) {
    const char* owner; unsigned long long generation;
    if (!PyArg_ParseTuple(args, "sK", &owner, &generation)) return nullptr;
    if (!validOwner(owner)) return PyErr_Format(PyExc_ValueError, "Invalid plugin ID");
    if (auto runtime = activeRuntime.load()) runtime->activate(owner, generation);
    Py_RETURN_NONE;
}
PyObject* publish(PyObject*, PyObject* args) {
    const char* text;
    if (!PyArg_ParseTuple(args, "s", &text)) return nullptr;
    if (auto runtime = activeRuntime.load()) runtime->publish(text);
    Py_RETURN_NONE;
}
PyObject* logMessage(PyObject*, PyObject* args) {
    const char *owner, *text;
    if (!PyArg_ParseTuple(args, "ss", &owner, &text)) return nullptr;
    if (auto runtime = activeRuntime.load()) runtime->log(owner, text);
    Py_RETURN_NONE;
}
PyMethodDef methods[] = {
    {"command", command, METH_VARARGS, nullptr},
    {"activate", activate, METH_VARARGS, nullptr},
    {"publish", publish, METH_VARARGS, nullptr},
    {"log", logMessage, METH_VARARGS, nullptr},
    {nullptr, nullptr, 0, nullptr}
};
PyModuleDef module = {PyModuleDef_HEAD_INIT, "_mucho_native", nullptr, -1, methods, nullptr, nullptr, nullptr, nullptr};
PyObject* initNative() { return PyModule_Create(&module); }

std::string pythonError() {
    PyObject *type = nullptr, *value = nullptr, *trace = nullptr;
    PyErr_Fetch(&type, &value, &trace);
    PyErr_NormalizeException(&type, &value, &trace);
    auto text = value ? PyObject_Str(value) : nullptr;
    auto utf8 = text ? PyUnicode_AsUTF8(text) : nullptr;
    std::string result = utf8 ? utf8 : "Unknown Python error";
    Py_XDECREF(text); Py_XDECREF(type); Py_XDECREF(value); Py_XDECREF(trace);
    PyErr_Clear();
    return result;
}
struct Ref {
    PyObject* object;
    ~Ref() { Py_XDECREF(object); }
};
}

namespace mucho {
Runtime::~Runtime() { stop(); }
void Runtime::state(std::string stateValue, std::string detail) {
    std::lock_guard lock(m_mutex); m_status.state = std::move(stateValue); m_status.detail = std::move(detail);
}
bool Runtime::start(Config config) {
    std::lock_guard lock(m_mutex);
    if (m_worker.joinable()) return false;
    Runtime* empty = nullptr;
    if (!activeRuntime.compare_exchange_strong(empty, this)) return false;
    m_stop = false; m_status = {}; m_status.state = "starting";
    m_worker = std::thread([this, config = std::move(config)] { run(config); });
    return true;
}
void Runtime::requestStop() {
    { std::lock_guard lock(m_mutex); m_stop = true; }
    m_wake.notify_all();
}
void Runtime::stop() {
    requestStop();
    if (m_worker.joinable()) m_worker.join();
    std::lock_guard lock(m_mutex);
    m_active.clear(); m_commands.clear(); m_controls.clear(); m_events.clear();
}
bool Runtime::emit(std::string name, std::string payload, unsigned long long scene) {
    std::lock_guard lock(m_mutex);
    if (m_stop || m_status.state != "ready" || name.size() > 64 || payload.size() > 16384) return false;
    if (m_events.size() >= 128) { ++m_status.droppedEvents; return false; }
    m_events.push_back({std::move(name), std::move(payload), scene}); m_wake.notify_one(); return true;
}
bool Runtime::control(std::string json) {
    std::lock_guard lock(m_mutex);
    if (m_stop || m_status.state != "ready" || json.size() > 1024 || m_controls.size() >= 32) return false;
    m_controls.push_back(std::move(json)); m_wake.notify_one(); return true;
}
bool Runtime::submit(Command command) {
    std::lock_guard lock(m_mutex);
    auto owner = m_active.find(command.owner);
    if (!validOwner(command.owner) || command.payload.size() > 8192 ||
        owner == m_active.end() || owner->second != command.generation ||
        (command.operation != "notice" && command.operation != "pulse" && command.operation != "button")) return false;
    if (m_commands.size() >= 128) { ++m_status.droppedCommands; return false; }
    m_commands.push_back(std::move(command)); return true;
}
void Runtime::activate(std::string owner, unsigned long long generation) {
    std::lock_guard lock(m_mutex);
    if (!validOwner(owner)) return;
    m_active[owner] = generation;
    std::erase_if(m_commands, [&](Command const& command) { return command.owner == owner; });
}
void Runtime::publish(std::string json) {
    std::lock_guard lock(m_mutex); if (json.size() <= 262144) m_status.plugins = std::move(json);
}
void Runtime::log(std::string owner, std::string text) {
    std::lock_guard lock(m_mutex);
    if (m_status.logs.size() >= 100) m_status.logs.erase(m_status.logs.begin());
    m_status.logs.push_back(owner.substr(0,64) + ": " + text.substr(0,1000));
}
bool Runtime::current(Command const& command) {
    std::lock_guard lock(m_mutex);
    auto owner = m_active.find(command.owner);
    return !m_stop && owner != m_active.end() && owner->second == command.generation;
}
std::vector<Command> Runtime::drain(std::size_t limit) {
    std::vector<Command> commands;
    std::lock_guard lock(m_mutex);
    while (!m_commands.empty() && commands.size() < limit) {
        auto command = std::move(m_commands.front()); m_commands.pop_front();
        auto owner = m_active.find(command.owner);
        if (owner != m_active.end() && owner->second == command.generation) commands.push_back(std::move(command));
    }
    return commands;
}
Status Runtime::status() { std::lock_guard lock(m_mutex); return m_status; }

void Runtime::run(Config config) {
    bool initialized = false;
    try {
        if (Py_IsInitialized()) throw std::runtime_error("Another embedded Python interpreter is already running");
        if (PyImport_AppendInittab("_mucho_native", initNative) < 0) throw std::runtime_error("Unable to register native API");
        PyConfig settings;
        PyConfig_InitIsolatedConfig(&settings);
        settings.install_signal_handlers = 0;
        settings.write_bytecode = 0;
        settings.site_import = 0;
        settings.user_site_directory = 0;
        if (!config.pythonHome.empty()) {
            auto wide = config.pythonHome.wstring();
            auto status = PyConfig_SetString(&settings, &settings.home, wide.c_str());
            if (PyStatus_Exception(status)) { PyConfig_Clear(&settings); throw std::runtime_error("Invalid Python home"); }
        }
        auto initializedStatus = Py_InitializeFromConfig(&settings);
        std::string initError = initializedStatus.err_msg ? initializedStatus.err_msg : "Python initialization failed";
        PyConfig_Clear(&settings);
        if (PyStatus_Exception(initializedStatus)) throw std::runtime_error(initError);
        initialized = true;
        {
            auto path = config.sdk.u8string();
            Ref sdkPath{PyUnicode_DecodeUTF8(reinterpret_cast<char const*>(path.data()), path.size(), "strict")};
            if (!sdkPath.object || PyList_Insert(PySys_GetObject("path"), 0, sdkPath.object) < 0)
                throw std::runtime_error(pythonError());
            Ref sdk{PyImport_ImportModule("mucho._manager")};
            if (!sdk.object) throw std::runtime_error(pythonError());
            auto pluginPath = config.plugins.u8string();
            Ref root{PyUnicode_DecodeUTF8(reinterpret_cast<char const*>(pluginPath.data()), pluginPath.size(), "strict")};
            Ref manager{root.object ? PyObject_CallMethod(sdk.object, "Manager", "O", root.object) : nullptr};
            if (!manager.object) throw std::runtime_error(pythonError());
            state("ready");
            for (;;) {
                auto threadState = PyEval_SaveThread();
                Event event; std::string controlValue; bool stopping;
                {
                    std::unique_lock lock(m_mutex);
                    m_wake.wait(lock, [this] { return m_stop || !m_controls.empty() || !m_events.empty(); });
                    stopping = m_stop;
                    if (!stopping && !m_controls.empty()) {
                        controlValue = std::move(m_controls.front()); m_controls.pop_front();
                    } else if (!stopping && !m_events.empty()) {
                        event = std::move(m_events.front()); m_events.pop_front();
                    }
                }
                PyEval_RestoreThread(threadState);
                if (stopping) break;
                Ref result{!controlValue.empty() ?
                    PyObject_CallMethod(manager.object, "control", "s", controlValue.c_str()) :
                    PyObject_CallMethod(manager.object, "dispatch", "ssK", event.name.c_str(), event.payload.c_str(), event.scene)};
                if (!result.object) log("runtime", pythonError());
            }
            Ref shutdown{PyObject_CallMethod(manager.object, "shutdown", nullptr)};
            if (!shutdown.object) log("runtime", pythonError());
        }
        Py_FinalizeEx(); initialized = false;
        state("stopped");
    } catch (std::exception const& error) {
        state("failed", error.what());
        if (initialized) Py_FinalizeEx();
    }
    activeRuntime.store(nullptr);
}
}
