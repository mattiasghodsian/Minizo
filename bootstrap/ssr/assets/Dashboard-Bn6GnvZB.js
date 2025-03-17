import { mergeProps, useSSRContext, ref, watch, resolveComponent, unref, withCtx, createBlock, openBlock, Fragment, renderList, toDisplayString, createTextVNode, createVNode, createCommentVNode, withModifiers } from "vue";
import { ssrRenderAttrs, ssrRenderList, ssrInterpolate, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { _ as _sfc_main$2 } from "./AuthenticatedLayout-CiAYwLCu.js";
import { usePoll, useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$3 } from "./InputError-D7Pvlg8p.js";
import { useToast } from "vue-toast-notification";
import "./Docker-C_fQ0Mgd.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main$1 = {
  __name: "QueueTable",
  __ssrInlineRender: true,
  props: {
    rows: {
      type: Array,
      default: []
    }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "bg-gray-800 items-center rounded-lg shadow-md text-gray-400 relative" }, _attrs))}><h1 class="text-white text-xl absolute uppercase -top-6 left-4 bg-gray-800 px-2 rounded-md">Queues</h1><div class="overflow-x-auto"><table class="min-w-full divide-gray-700"><thead><tr><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">UUID</th><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">URL</th><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Directory</th><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Format</th><th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Timestamp</th></tr></thead><tbody class="divide-y divide-gray-700"><!--[-->`);
      ssrRenderList(__props.rows, (row, index) => {
        _push(`<tr class="hover:bg-gray-700"><td class="px-6 py-4 whitespace-nowrap text-sm">${ssrInterpolate(row.uuid)}</td><td class="px-6 py-4 whitespace-nowrap text-sm"><a${ssrRenderAttr("href", row.data.command.url)} target="_blank" class="text-minizo-green">Open</a></td><td class="px-6 py-4 whitespace-nowrap text-sm">${ssrInterpolate(row.data.command.directory)}</td><td class="px-6 py-4 whitespace-nowrap text-sm">${ssrInterpolate(row.data.command.format)}</td><td class="px-6 py-4 whitespace-nowrap text-sm">${ssrInterpolate(row.data.created_at)}</td></tr>`);
      });
      _push(`<!--]--></tbody></table></div></div>`);
    };
  }
};
const _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/QueueTable.vue");
  return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
const _sfc_main = {
  __name: "Dashboard",
  __ssrInlineRender: true,
  props: {
    message: {
      type: String,
      default: ""
    },
    messageType: {
      type: String,
      default: ""
    },
    queues: {
      type: Array,
      default: []
    }
  },
  setup(__props) {
    const props = __props;
    usePoll(5e3, {
      only: ["queues"]
    }, {
      keepAlive: true,
      autoStart: true
    });
    const directoryRef = ref(null);
    const formatRef = ref(null);
    const form = useForm({
      url: "",
      directory: "",
      format: ""
    });
    const SelectionDirectory = (directory) => {
      var _a;
      form.directory = directory;
      (_a = directoryRef.value) == null ? void 0 : _a.close();
    };
    const SelectionFormat = (format) => {
      var _a;
      form.format = format;
      (_a = formatRef.value) == null ? void 0 : _a.close();
    };
    const submit = () => {
      form.post(route("dashboard"), {
        preserveScroll: true,
        onSuccess: () => form.reset("url")
      });
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
    return (_ctx, _push, _parent, _attrs) => {
      const _component_TextInput = resolveComponent("TextInput");
      const _component_DropDownBox = resolveComponent("DropDownBox");
      const _component_PrimaryButton = resolveComponent("PrimaryButton");
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), { title: "Download" }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$2, null, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<form class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md"${_scopeId}><h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md"${_scopeId}>Download</h1><div class="flex gap-3"${_scopeId}><div class="flex flex-grow items-center"${_scopeId}>`);
            _push2(ssrRenderComponent(_component_TextInput, {
              id: "url",
              type: "url",
              class: "block w-full",
              modelValue: unref(form).url,
              "onUpdate:modelValue": ($event) => unref(form).url = $event,
              placeholder: "https://music.youtube.com/watch?v=xxxxxxxxxxx",
              required: "",
              autofocus: ""
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$3, {
              class: "mt-2",
              message: unref(form).errors.url
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="flex items-center"${_scopeId}>`);
            _push2(ssrRenderComponent(_component_DropDownBox, {
              value: unref(form).directory,
              defaultValue: "Select Directory",
              ref_key: "directoryRef",
              ref: directoryRef
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<!--[-->`);
                  ssrRenderList(_ctx.$page.props.library.directories, (directory, index) => {
                    _push3(`<span class="block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200" role="menuitem"${_scopeId2}>${ssrInterpolate(directory.name)}</span>`);
                  });
                  _push3(`<!--]-->`);
                } else {
                  return [
                    (openBlock(true), createBlock(Fragment, null, renderList(_ctx.$page.props.library.directories, (directory, index) => {
                      return openBlock(), createBlock("span", {
                        key: index,
                        onClick: ($event) => SelectionDirectory(directory.name),
                        class: "block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200",
                        role: "menuitem"
                      }, toDisplayString(directory.name), 9, ["onClick"]);
                    }), 128))
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`</div><div class="flex items-center"${_scopeId}>`);
            _push2(ssrRenderComponent(_component_DropDownBox, {
              value: unref(form).format,
              "default-value": "Select format",
              ref_key: "formatRef",
              ref: formatRef
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<!--[-->`);
                  ssrRenderList(_ctx.$page.props.library.formats, (format, index) => {
                    _push3(`<span class="block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200" role="menuitem"${_scopeId2}>${ssrInterpolate(format)}</span>`);
                  });
                  _push3(`<!--]-->`);
                } else {
                  return [
                    (openBlock(true), createBlock(Fragment, null, renderList(_ctx.$page.props.library.formats, (format, index) => {
                      return openBlock(), createBlock("span", {
                        key: index,
                        onClick: ($event) => SelectionFormat(format),
                        class: "block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200",
                        role: "menuitem"
                      }, toDisplayString(format), 9, ["onClick"]);
                    }), 128))
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`</div><div class="flex items-center"${_scopeId}>`);
            _push2(ssrRenderComponent(_component_PrimaryButton, {
              disabled: unref(form).processing,
              type: "submit",
              class: "bg-minizo-dark"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`Download`);
                } else {
                  return [
                    createTextVNode("Download")
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`</div></div></form><div class="flex text-sm text-gray-400 text-center -mt-5 px-5"${_scopeId}><p${_scopeId}>Downloading copyrighted content without authorization is illegal. This project is for educational purposes only. Ensure you have the right to download and use the content.</p></div>`);
            if (__props.queues.length > 0) {
              _push2(ssrRenderComponent(_sfc_main$1, { rows: __props.queues }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"]),
                class: "bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md"
              }, [
                createVNode("h1", { class: "text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md" }, "Download"),
                createVNode("div", { class: "flex gap-3" }, [
                  createVNode("div", { class: "flex flex-grow items-center" }, [
                    createVNode(_component_TextInput, {
                      id: "url",
                      type: "url",
                      class: "block w-full",
                      modelValue: unref(form).url,
                      "onUpdate:modelValue": ($event) => unref(form).url = $event,
                      placeholder: "https://music.youtube.com/watch?v=xxxxxxxxxxx",
                      required: "",
                      autofocus: ""
                    }, null, 8, ["modelValue", "onUpdate:modelValue"]),
                    createVNode(_sfc_main$3, {
                      class: "mt-2",
                      message: unref(form).errors.url
                    }, null, 8, ["message"])
                  ]),
                  createVNode("div", { class: "flex items-center" }, [
                    createVNode(_component_DropDownBox, {
                      value: unref(form).directory,
                      defaultValue: "Select Directory",
                      ref_key: "directoryRef",
                      ref: directoryRef
                    }, {
                      default: withCtx(() => [
                        (openBlock(true), createBlock(Fragment, null, renderList(_ctx.$page.props.library.directories, (directory, index) => {
                          return openBlock(), createBlock("span", {
                            key: index,
                            onClick: ($event) => SelectionDirectory(directory.name),
                            class: "block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200",
                            role: "menuitem"
                          }, toDisplayString(directory.name), 9, ["onClick"]);
                        }), 128))
                      ]),
                      _: 1
                    }, 8, ["value"])
                  ]),
                  createVNode("div", { class: "flex items-center" }, [
                    createVNode(_component_DropDownBox, {
                      value: unref(form).format,
                      "default-value": "Select format",
                      ref_key: "formatRef",
                      ref: formatRef
                    }, {
                      default: withCtx(() => [
                        (openBlock(true), createBlock(Fragment, null, renderList(_ctx.$page.props.library.formats, (format, index) => {
                          return openBlock(), createBlock("span", {
                            key: index,
                            onClick: ($event) => SelectionFormat(format),
                            class: "block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200",
                            role: "menuitem"
                          }, toDisplayString(format), 9, ["onClick"]);
                        }), 128))
                      ]),
                      _: 1
                    }, 8, ["value"])
                  ]),
                  createVNode("div", { class: "flex items-center" }, [
                    createVNode(_component_PrimaryButton, {
                      disabled: unref(form).processing,
                      type: "submit",
                      class: "bg-minizo-dark"
                    }, {
                      default: withCtx(() => [
                        createTextVNode("Download")
                      ]),
                      _: 1
                    }, 8, ["disabled"])
                  ])
                ])
              ], 32),
              createVNode("div", { class: "flex text-sm text-gray-400 text-center -mt-5 px-5" }, [
                createVNode("p", null, "Downloading copyrighted content without authorization is illegal. This project is for educational purposes only. Ensure you have the right to download and use the content.")
              ]),
              __props.queues.length > 0 ? (openBlock(), createBlock(_sfc_main$1, {
                key: 0,
                rows: __props.queues
              }, null, 8, ["rows"])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Dashboard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
