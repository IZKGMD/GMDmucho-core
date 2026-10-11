option(MUCHO_WITH_PYTHON "Embed CPython into the standalone loader" ${WIN32})
target_compile_definitions(geode-loader PRIVATE MUCHO_LOADER=1)
target_sources(geode-loader PRIVATE "${CMAKE_CURRENT_LIST_DIR}/native/GameAdapter.cpp")
if(MUCHO_WITH_PYTHON)
  find_package(Python3 3.12 REQUIRED COMPONENTS Development.Embed)
  target_sources(geode-loader PRIVATE "${CMAKE_CURRENT_LIST_DIR}/native/Runtime.cpp")
  # Upstream uses the plain target_link_libraries signature. Append only the
  # implementation dependency, without exporting Python into the Geode SDK.
  set_property(TARGET geode-loader APPEND PROPERTY LINK_LIBRARIES Python3::Python)
  # Runtime.cpp owns an interpreter on one worker; it must not be merged into Geode unity TUs.
  set_source_files_properties("${CMAKE_CURRENT_LIST_DIR}/native/Runtime.cpp" TARGET_DIRECTORY geode-loader PROPERTIES SKIP_UNITY_BUILD_INCLUSION ON SKIP_PRECOMPILE_HEADERS ON)
else()
  target_sources(geode-loader PRIVATE "${CMAKE_CURRENT_LIST_DIR}/native/RuntimeStub.cpp")
endif()
set_source_files_properties("${CMAKE_CURRENT_LIST_DIR}/native/GameAdapter.cpp" TARGET_DIRECTORY geode-loader PROPERTIES SKIP_UNITY_BUILD_INCLUSION ON)
