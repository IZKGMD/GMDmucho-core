#include "Runtime.hpp"
namespace mucho {
Runtime::~Runtime() = default;
bool Runtime::start(Config) { m_status.state="unavailable"; m_status.detail="Python runtime is not bundled for this platform"; return false; }
void Runtime::stop() {}
void Runtime::requestStop() {}
bool Runtime::emit(std::string,std::string,unsigned long long) { return false; }
bool Runtime::control(std::string) { return false; }
bool Runtime::submit(Command) { return false; }
void Runtime::activate(std::string,unsigned long long) {}
void Runtime::publish(std::string) {}
void Runtime::log(std::string,std::string) {}
bool Runtime::current(Command const&) { return false; }
std::vector<Command> Runtime::drain(std::size_t) { return {}; }
Status Runtime::status() { return m_status; }
}
