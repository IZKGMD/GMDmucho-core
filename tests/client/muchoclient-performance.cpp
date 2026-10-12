#include "../../clients/geode/muchoclient/src/MuchoPerformanceRules.hpp"
#include <cassert>
#include <iostream>
#include <string>
using namespace mucho::performance;

int main() {
    DeferredWork work; int applied = 0; bool alive = true;
    for (int i=0;i<20;++i) assert(work.add([&] { return alive; },[&] { ++applied; }));
    for (int frame=0;frame<1200;++frame) work.drain(true);
    assert(applied == 0); // Ten seconds at 120 FPS: no decorative work in a level.
    work.drain(false); assert(applied == 2);
    alive = false; work.drain(false); assert(work.empty() && applied == 2);
    for (int i=0;i<256;++i) assert(work.add([] { return true; },[] {}));
    assert(!work.add([] { return true; },[] {}));
    work.clear(); assert(work.empty());

    SharedRequests<int,int,std::string> requests;
    int delivered = 0; bool first = true, second = true;
    assert(requests.add(1,10,[&] { return first; },[&](auto const& result) { assert(result == "texture"); ++delivered; }));
    assert(requests.add(1,10,[&] { return second; },[&](auto const&) { ++delivered; }));
    assert(requests.size() == 1); // Two views share a single download/decode.
    assert(requests.add(2,20,[] { return true; },[](auto const&) {}));
    assert(requests.add(3,30,[] { return true; },[](auto const&) {}));
    assert(requests.take()->first == 1);
    assert(requests.take()->first == 2);
    assert(!requests.take() && requests.active() == 2);
    first = false; requests.complete(1,"texture"); assert(delivered == 1);
    assert(requests.take()->first == 3);
    requests.discard(2); requests.discard(3); assert(requests.active() == 0);
    assert(requests.add(4,40,[] { return false; },[](auto const&) { assert(false); }));
    assert(!requests.take() && requests.size() == 0);
    for (int i=0;i<128;++i) assert(requests.add(i,i,[] { return true; },[](auto const&) {}));
    assert(!requests.add(999,0,[] { return true; },[](auto const&) {}));
    assert(requests.size() == 128);
    requests.clear(); assert(requests.size() == 0 && requests.active() == 0);

    WeightedCache<int,std::string> cache(8,3);
    cache.put(1,"first",3); cache.put(2,"second",3);
    assert(cache.get(1).value() == "first");
    cache.put(3,"third",3); assert(!cache.get(2)); // Evict least recently used.
    assert(cache.weight() == 6 && cache.size() == 2);
    cache.put(1,"replacement",1); assert(cache.weight() == 4);
    cache.put(9,"too large",9); assert(!cache.get(9));
    cache.clear(); assert(cache.weight() == 0 && cache.size() == 0);
    WeightedCache<int,int> countLimit(1000,2);
    countLimit.put(1,1,1); countLimit.put(2,2,1); countLimit.put(3,3,1);
    assert(countLimit.size() == 2 && !countLimit.get(1));

    // A cached 50-row page paints each new binding once, with no repaint of
    // previous rows; a subsequent unchanged batch adds no font rebuilds.
    unsigned long long painted[50]{}; unsigned paints = 0;
    for (auto& revision : painted) if (repaintNeeded(revision,1)) { revision = 1; ++paints; }
    for (auto& revision : painted) if (repaintNeeded(revision,1)) { revision = 1; ++paints; }
    assert(paints == 50);
    if (repaintNeeded(painted[17],2)) { painted[17] = 2; ++paints; }
    assert(paints == 51);
    std::cout << "PASS: gameplay deferral, frame budget, coalescing, concurrency, stale views, queue bounds, texture LRU and appearance revisions\n";
}
