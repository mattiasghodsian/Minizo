import { mergeProps, useSSRContext, ref, watch, computed, resolveComponent, unref, withCtx, createVNode, createBlock, createCommentVNode, toDisplayString, openBlock, withDirectives, Fragment, renderList, withModifiers, vShow } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrIncludeBooleanAttr, ssrRenderList, ssrRenderClass, ssrRenderComponent, ssrRenderStyle } from "vue/server-renderer";
import { _ as _sfc_main$2 } from "./AuthenticatedLayout-CiAYwLCu.js";
import { usePoll, useForm, usePage, Head } from "@inertiajs/vue3";
import { useToast } from "vue-toast-notification";
import "./Docker-C_fQ0Mgd.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main$1 = {
  __name: "Pagination",
  __ssrInlineRender: true,
  props: {
    currentPage: {
      type: Number,
      required: true
    },
    totalPages: {
      type: Number,
      required: true
    },
    perPage: {
      type: Number,
      required: true
    },
    totalItems: {
      type: Number,
      required: true
    }
  },
  emits: ["page-changed"],
  setup(__props, { emit: __emit }) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "flex items-center justify-between" }, _attrs))}><div class="flex flex-1 justify-between sm:hidden"><button${ssrIncludeBooleanAttr(__props.currentPage === 1) ? " disabled" : ""} class="relative inline-flex items-center px-4 py-2 text-sm font-medium rounded-md text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"> Previous </button><button${ssrIncludeBooleanAttr(__props.currentPage === __props.totalPages) ? " disabled" : ""} class="relative ml-3 inline-flex items-center px-4 py-2 text-sm font-medium rounded-md text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"> Next </button></div><div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between"><div><p class="text-sm text-gray-400"> Showing <span class="font-medium">${ssrInterpolate((__props.currentPage - 1) * __props.perPage + 1)}</span> to <span class="font-medium">${ssrInterpolate(Math.min(__props.currentPage * __props.perPage, __props.totalItems))}</span> of <span class="font-medium">${ssrInterpolate(__props.totalItems)}</span> results </p></div><div><nav class="isolate inline-flex -space-x-px rounded-md shadow-sm gap-1"><button${ssrIncludeBooleanAttr(__props.currentPage === 1) ? " disabled" : ""} class="relative inline-flex items-center px-3.5 py-1 rounded-full text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"><span class="sr-only">Previous</span> ← </button><!--[-->`);
      ssrRenderList(__props.totalPages, (page) => {
        _push(`<button class="${ssrRenderClass([
          page === __props.currentPage ? "bg-gray-700" : "bg-gray-800 hover:bg-gray-700",
          "relative inline-flex items-center px-3.5 py-1 text-sm rounded-full font-medium text-gray-400"
        ])}">${ssrInterpolate(page)}</button>`);
      });
      _push(`<!--]--><button${ssrIncludeBooleanAttr(__props.currentPage === __props.totalPages) ? " disabled" : ""} class="relative inline-flex items-center px-3.5 py-1 rounded-full text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"><span class="sr-only">Next</span> → </button></nav></div></div></div>`);
    };
  }
};
const _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Pagination.vue");
  return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
const _sfc_main = {
  __name: "View",
  __ssrInlineRender: true,
  props: {
    files: {
      type: Array
    },
    totalFiles: {
      type: Number
    },
    currentDirectory: {
      type: String
    },
    message: {
      type: String,
      default: ""
    },
    messageType: {
      type: String,
      default: ""
    }
  },
  setup(__props) {
    const props = __props;
    usePoll(1e4, {
      only: ["files", "totalFiles"]
    }, {
      keepAlive: true,
      autoStart: true
    });
    const form = useForm({
      currentFile: null,
      directory: props.currentDirectory
    });
    const toggleToolMenu = ref(false);
    const menuPosition = ref({ x: 0, y: 0 });
    const showToolMenu = (event, file) => {
      event.preventDefault();
      menuPosition.value = {
        x: event.clientX,
        y: event.clientY
      };
      toggleToolMenu.value = !toggleToolMenu.value;
      form.currentFile = file;
    };
    const deleteFile = () => {
      toggleToolMenu.value = !toggleToolMenu.value;
      form.delete(route("library.destroy", { directory: props.currentDirectory }), {
        preserveScroll: true,
        onFinish: () => {
          form.reset();
        }
      });
    };
    const MoveFile = () => {
      toggleToolMenu.value = !toggleToolMenu.value;
    };
    const SearchYT = () => {
      toggleToolMenu.value = !toggleToolMenu.value;
      const trackName = form.currentFile.replace(/\.[^/.]+$/, "");
      const query = trackName.replace(/\s+/g, "+");
      const url = `https://music.youtube.com/search?q=${query}`;
      window.open(url, "_blank");
    };
    const writeMeta = () => {
      toggleToolMenu.value = !toggleToolMenu.value;
    };
    const $toast = useToast();
    watch(
      () => props.message,
      (newMessage) => {
        if (newMessage) {
          const cleanMessage = newMessage.includes("_") ? newMessage.split("_")[0] : newMessage;
          if (props.messageType === "success") {
            $toast.success(cleanMessage);
          } else if (props.messageType === "error") {
            $toast.error(cleanMessage);
          } else {
            $toast.info(cleanMessage);
          }
        }
      }
    );
    const currentPage = ref(1);
    const perPage = ref(usePage().props.auth.user.pagination_size ?? 10);
    const paginatedFiles = computed(() => {
      const start = (currentPage.value - 1) * perPage.value;
      const end = start + perPage.value;
      return props.files.slice(start, end);
    });
    const totalPages = computed(() => {
      return Math.ceil(props.files.length / perPage.value);
    });
    const handlePageChange = (page) => {
      currentPage.value = page;
    };
    return (_ctx, _push, _parent, _attrs) => {
      const _component_FolderIcon = resolveComponent("FolderIcon");
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), { title: __props.currentDirectory }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$2, null, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="flex items-center gap-3 text-gray-400"${_scopeId}>`);
            _push2(ssrRenderComponent(_component_FolderIcon, { class: "w-7 h-7 fill-gray-400" }, null, _parent2, _scopeId));
            _push2(`<span class="text-lg"${_scopeId}>${ssrInterpolate(__props.currentDirectory)}</span><span class="bg-gray-800 px-2.5 py-0.5 rounded-full text-gray400"${_scopeId}>${ssrInterpolate(__props.totalFiles)}</span></div>`);
            if (__props.totalFiles > 0) {
              _push2(`<div class="bg-gray-800 items-center rounded-lg shadow-md text-gray-400 relative"${_scopeId}><div class="overflow-x-auto"${_scopeId}><table class="min-w-full divide-gray-700 relative"${_scopeId}><thead class="border-b border-gray-700"${_scopeId}><tr${_scopeId}><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider"${_scopeId}>File</th><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider"${_scopeId}>Format</th><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider"${_scopeId}>Size</th><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider"${_scopeId}>Last modified</th></tr></thead><tbody class="divide-y divide-gray-700"${_scopeId}><!--[-->`);
              ssrRenderList(paginatedFiles.value, (file, index) => {
                _push2(`<tr class="hover:bg-gray-700"${_scopeId}><td class="px-6 py-4 whitespace-nowrap text-sm max-w-96 truncate"${_scopeId}>${ssrInterpolate(file.name_clean)}</td><td class="px-6 py-4 whitespace-nowrap text-sm"${_scopeId}>${ssrInterpolate(file.format)}</td><td class="px-6 py-4 whitespace-nowrap text-sm"${_scopeId}>${ssrInterpolate(file.size)}</td><td class="px-6 py-4 whitespace-nowrap text-sm"${_scopeId}>${ssrInterpolate(file.last_modified)}</td></tr>`);
              });
              _push2(`<!--]--></tbody><div style="${ssrRenderStyle([
                toggleToolMenu.value ? null : { display: "none" },
                {
                  top: `${menuPosition.value.y}px`,
                  left: `${menuPosition.value.x}px`
                }
              ])}" class="fixed z-50 bg-gray-800 border border-gray-700 min-w-32 rounded-lg shadow-md text-gray-400"${_scopeId}><ul${_scopeId}><li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer"${_scopeId}>Edit</li><li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer"${_scopeId}>Move</li><li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer"${_scopeId}>Search</li><li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer"${_scopeId}>Delete</li></ul></div></table></div><div class="border-t border-gray-700 px-6 py-3"${_scopeId}>`);
              _push2(ssrRenderComponent(_sfc_main$1, {
                "current-page": currentPage.value,
                "total-pages": totalPages.value,
                "per-page": perPage.value,
                "total-items": __props.files.length,
                onPageChanged: handlePageChange
              }, null, _parent2, _scopeId));
              _push2(`</div></div>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", { class: "flex items-center gap-3 text-gray-400" }, [
                createVNode(_component_FolderIcon, { class: "w-7 h-7 fill-gray-400" }),
                createVNode("span", { class: "text-lg" }, toDisplayString(__props.currentDirectory), 1),
                createVNode("span", { class: "bg-gray-800 px-2.5 py-0.5 rounded-full text-gray400" }, toDisplayString(__props.totalFiles), 1)
              ]),
              __props.totalFiles > 0 ? (openBlock(), createBlock("div", {
                key: 0,
                class: "bg-gray-800 items-center rounded-lg shadow-md text-gray-400 relative"
              }, [
                createVNode("div", { class: "overflow-x-auto" }, [
                  createVNode("table", { class: "min-w-full divide-gray-700 relative" }, [
                    createVNode("thead", { class: "border-b border-gray-700" }, [
                      createVNode("tr", null, [
                        createVNode("th", { class: "px-6 py-3 text-left text-xs font-bold uppercase tracking-wider" }, "File"),
                        createVNode("th", { class: "px-6 py-3 text-left text-xs font-bold uppercase tracking-wider" }, "Format"),
                        createVNode("th", { class: "px-6 py-3 text-left text-xs font-bold uppercase tracking-wider" }, "Size"),
                        createVNode("th", { class: "px-6 py-3 text-left text-xs font-bold uppercase tracking-wider" }, "Last modified")
                      ])
                    ]),
                    createVNode("tbody", { class: "divide-y divide-gray-700" }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(paginatedFiles.value, (file, index) => {
                        return openBlock(), createBlock("tr", {
                          key: index,
                          class: "hover:bg-gray-700",
                          onContextmenu: withModifiers(($event) => showToolMenu($event, file.name), ["right"])
                        }, [
                          createVNode("td", { class: "px-6 py-4 whitespace-nowrap text-sm max-w-96 truncate" }, toDisplayString(file.name_clean), 1),
                          createVNode("td", { class: "px-6 py-4 whitespace-nowrap text-sm" }, toDisplayString(file.format), 1),
                          createVNode("td", { class: "px-6 py-4 whitespace-nowrap text-sm" }, toDisplayString(file.size), 1),
                          createVNode("td", { class: "px-6 py-4 whitespace-nowrap text-sm" }, toDisplayString(file.last_modified), 1)
                        ], 40, ["onContextmenu"]);
                      }), 128))
                    ]),
                    withDirectives(createVNode("div", {
                      class: "fixed z-50 bg-gray-800 border border-gray-700 min-w-32 rounded-lg shadow-md text-gray-400",
                      style: {
                        top: `${menuPosition.value.y}px`,
                        left: `${menuPosition.value.x}px`
                      }
                    }, [
                      createVNode("ul", null, [
                        createVNode("li", {
                          class: "hover:bg-gray-700 px-5 py-2.5 cursor-pointer",
                          onClick: writeMeta
                        }, "Edit"),
                        createVNode("li", {
                          class: "hover:bg-gray-700 px-5 py-2.5 cursor-pointer",
                          onClick: MoveFile
                        }, "Move"),
                        createVNode("li", {
                          class: "hover:bg-gray-700 px-5 py-2.5 cursor-pointer",
                          onClick: SearchYT
                        }, "Search"),
                        createVNode("li", {
                          class: "hover:bg-gray-700 px-5 py-2.5 cursor-pointer",
                          onClick: deleteFile
                        }, "Delete")
                      ])
                    ], 4), [
                      [vShow, toggleToolMenu.value]
                    ])
                  ])
                ]),
                createVNode("div", { class: "border-t border-gray-700 px-6 py-3" }, [
                  createVNode(_sfc_main$1, {
                    "current-page": currentPage.value,
                    "total-pages": totalPages.value,
                    "per-page": perPage.value,
                    "total-items": __props.files.length,
                    onPageChanged: handlePageChange
                  }, null, 8, ["current-page", "total-pages", "per-page", "total-items"])
                ])
              ])) : createCommentVNode("", true)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Library/View.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
