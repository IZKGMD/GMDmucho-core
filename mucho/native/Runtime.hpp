#pragma once

#include <atomic>
#include <condition_variable>
#include <deque>
#include <filesystem>
#include <mutex>
#include <string>
#include <thread>
#include <unordered_map>
#include <vector>

namespace mucho {
struct Command {
    std::string owner, operation, payload;
    unsigned long long generation = 0, scene = 0;
};
struct Status {
    std::string state = "stopped", detail, plugins = "[]";
    std::vector<std::string> logs;
    std::size_t droppedEvents = 0, droppedCommands = 0;
};
struct Config {
    std::filesystem::path sdk, plugins, pythonHome;
};

// Private implementation; does not add or change Geode's exported ABI.
class Runtime {
    struct Event { std::string name, payload; unsigned long long scene; };
    std::mutex m_mutex;
    std::condition_variable m_wake;
    std::thread m_worker;
    std::deque<Event> m_events;
    std::deque<std::string> m_controls;
    std::deque<Command> m_commands;
    std::unordered_map<std::string, unsigned long long> m_active;
    Status m_status;
    bool m_stop = false;
    void run(Config config);
    void state(std::string state, std::string detail = {});
public:
    Runtime() = default;
    Runtime(Runtime const&) = delete;
    Runtime& operator=(Runtime const&) = delete;
    ~Runtime();
    bool start(Config config);
    void requestStop();
    void stop();
    bool emit(std::string name, std::string payload, unsigned long long scene = 0);
    bool control(std::string json);
    bool submit(Command command);
    void activate(std::string owner, unsigned long long generation);
    void publish(std::string json);
    void log(std::string owner, std::string text);
    bool current(Command const& command);
    std::vector<Command> drain(std::size_t limit = 32);
    Status status();
};
}
