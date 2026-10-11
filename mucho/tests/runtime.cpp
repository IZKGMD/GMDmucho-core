#include "Runtime.hpp"
#include <chrono>
#include <filesystem>
#include <fstream>
#include <iostream>
#include <stdexcept>
#include <thread>

namespace fs=std::filesystem;
using namespace std::chrono_literals;
static unsigned checks=0;
void check(bool result,char const* name) { if(!result) throw std::runtime_error(name); ++checks; }
template<class F> bool wait(F&& condition) {
    auto until=std::chrono::steady_clock::now()+5s;
    while(std::chrono::steady_clock::now()<until) { if(condition()) return true; std::this_thread::sleep_for(5ms); }
    return false;
}
int main(int argc,char** argv) {
    try {
        check(argc>=3,"test arguments");
        auto source=fs::path(argv[1]);
        auto root=fs::temp_directory_path()/fs::path("mucho-runtime-"+std::to_string(std::chrono::steady_clock::now().time_since_epoch().count()));
        fs::create_directories(root);
        struct Cleanup { fs::path path; ~Cleanup(){std::error_code error;fs::remove_all(path,error);} } cleanup{root};
        fs::copy(source/"examples/hello-python",root/"hello-python",fs::copy_options::recursive);
        {
            mucho::Runtime bridge;
            check(!bridge.submit({"a","notice","{}",1,0}),"inactive owner rejected");
            bridge.activate("a",1);
            check(!bridge.submit({"a","execute","{}",1,0}),"unknown operation rejected");
            check(!bridge.submit({"a","notice",std::string(8193,'x'),1,0}),"oversized payload rejected");
            check(bridge.submit({"a","notice","{}",1,9}),"native command accepted");
            bridge.activate("a",2);
            check(bridge.drain().empty(),"generation change cancels pending commands");
            for(int i=0;i<128;++i) check(bridge.submit({"a","notice","{}",2,0}),"bounded queue accepts capacity");
            check(!bridge.submit({"a","notice","{}",2,0}),"queue refuses overflow");
            check(bridge.status().droppedCommands==1,"overflow is observable");
            check(bridge.drain(5).size()==5,"frame drain budget enforced");
            bridge.requestStop();
            check(!bridge.current({"a","notice","{}",2,0}),"shutdown invalidates pending commands");
        }
        mucho::Runtime runtime;
        check(runtime.start({source/"sdk",root,argv[2]}),"embedded interpreter starts");
        check(!runtime.start({source/"sdk",root,argv[2]}),"double start rejected");
        check(wait([&]{return runtime.status().state!="starting";}),"startup completes");
        if(runtime.status().state!="ready") throw std::runtime_error(runtime.status().detail);
        check(runtime.status().plugins.find("\"status\": \"loaded\"")!=std::string::npos,"real Python plugin loaded");
        check(runtime.emit("level.start","{\"level_id\":42}",17),"game event queued");
        std::vector<mucho::Command> commands;
        check(wait([&]{commands=runtime.drain();return !commands.empty();}),"Python executes bound C++ command");
        check(commands[0].operation=="pulse" && commands[0].scene==17 && commands[0].owner=="hello-python","scene and ownership preserved");
        check(!runtime.emit("level.start",std::string(16385,'x'),17),"oversized event rejected");
        check(runtime.control("{\"operation\":\"disable\",\"id\":\"hello-python\"}"),"disable queued");
        check(wait([&]{return runtime.status().plugins.find("\"status\": \"disabled\"")!=std::string::npos;}),"disable applied by worker");
        check(!runtime.current(commands[0]),"already-drained stale command rejected");
        check(runtime.control("{\"operation\":\"enable\",\"id\":\"hello-python\"}"),"enable queued");
        check(wait([&]{return runtime.status().plugins.find("\"status\": \"loaded\"")!=std::string::npos;}),"enable applied");
        check(runtime.control("{\"operation\":\"bad\",\"id\":\"hello-python\"}"),"invalid control processed safely");
        check(wait([&]{for(auto const& entry:runtime.status().logs) if(entry.find("Unknown control")!=std::string::npos)return true;return false;}),"bad control logged without killing worker");
        runtime.stop();
        check(runtime.status().state=="stopped","interpreter shutdown completes");
        check(fs::exists(root/".state.json"),"enable state persisted");
        std::cout<<"PASS: "<<checks<<" native bridge / embedded Python checks\n";
        return 0;
    } catch(std::exception const& error) { std::cerr<<"FAIL: "<<error.what()<<'\n';return 1; }
}
