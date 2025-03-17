import { computed, unref, withCtx, renderSlot, useSSRContext, mergeProps, ref, resolveComponent, createVNode, createTextVNode, toDisplayString } from "vue";
import { ssrRenderComponent, ssrRenderSlot, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderList, ssrInterpolate } from "vue/server-renderer";
import { A as ApplicationLogo, G as GithubIcon, D as DockerIcon } from "./Docker-C_fQ0Mgd.js";
import { Link } from "@inertiajs/vue3";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main$4 = {
  __name: "NavLink",
  __ssrInlineRender: true,
  props: {
    href: {
      type: String,
      required: true
    },
    active: {
      type: Boolean
    },
    external: {
      type: Boolean,
      default: false
    }
  },
  setup(__props) {
    const props = __props;
    const classes = computed(
      () => props.active ? "flex items-center gap-4 text-xl text-white fill-white bg-gray-800 px-6 py-2 border-l-2 border-minizo-green rounded-tr-lg rounded-br-lg" : "flex items-center gap-4 text-xl text-gray-400 px-6 py-2 hover:bg-gray-800 border-l-2 border-transparent hover:text-white hover:border-l-2 hover:border-minizo-green hover:rounded-tr-lg hover:rounded-br-lg"
    );
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      if (!__props.external) {
        _push(ssrRenderComponent(unref(Link), {
          href: __props.href,
          class: classes.value
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              ssrRenderSlot(_ctx.$slots, "default", {}, null, _push2, _parent2, _scopeId);
            } else {
              return [
                renderSlot(_ctx.$slots, "default")
              ];
            }
          }),
          _: 3
        }, _parent));
      } else {
        _push(`<!---->`);
      }
      if (__props.external) {
        _push(`<a${ssrRenderAttr("href", __props.href)} class="classes" target="_blank">`);
        ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
        _push(`</a>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup$4 = _sfc_main$4.setup;
_sfc_main$4.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/NavLink.vue");
  return _sfc_setup$4 ? _sfc_setup$4(props, ctx) : void 0;
};
const _sfc_main$3 = {};
function _sfc_ssrRender$2(_ctx, _push, _parent, _attrs) {
  _push(`<svg${ssrRenderAttrs(mergeProps({
    xmlns: "http://www.w3.org/2000/svg",
    viewBox: "0 0 512 512"
  }, _attrs))}><path d="M288 32c0-17.7-14.3-32-32-32s-32 14.3-32 32V274.7l-73.4-73.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3l128 128c12.5 12.5 32.8 12.5 45.3 0l128-128c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L288 274.7V32zM64 352c-35.3 0-64 28.7-64 64v32c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V416c0-35.3-28.7-64-64-64H346.5l-45.3 45.3c-25 25-65.5 25-90.5 0L165.5 352H64zm368 56a24 24 0 1 1 0 48 24 24 0 1 1 0-48z"></path></svg>`);
}
const _sfc_setup$3 = _sfc_main$3.setup;
_sfc_main$3.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Icons/Download.vue");
  return _sfc_setup$3 ? _sfc_setup$3(props, ctx) : void 0;
};
const DownloadIcon = /* @__PURE__ */ _export_sfc(_sfc_main$3, [["ssrRender", _sfc_ssrRender$2]]);
const _sfc_main$2 = {};
function _sfc_ssrRender$1(_ctx, _push, _parent, _attrs) {
  _push(`<svg${ssrRenderAttrs(mergeProps({
    xmlns: "http://www.w3.org/2000/svg",
    viewBox: "0 -960 960 960"
  }, _attrs))}><path d="m370-80-16-128q-13-5-24.5-12T307-235l-119 50L78-375l103-78q-1-7-1-13.5v-27q0-6.5 1-13.5L78-585l110-190 119 50q11-8 23-15t24-12l16-128h220l16 128q13 5 24.5 12t22.5 15l119-50 110 190-103 78q1 7 1 13.5v27q0 6.5-2 13.5l103 78-110 190-118-50q-11 8-23 15t-24 12L590-80H370Zm70-80h79l14-106q31-8 57.5-23.5T639-327l99 41 39-68-86-65q5-14 7-29.5t2-31.5q0-16-2-31.5t-7-29.5l86-65-39-68-99 42q-22-23-48.5-38.5T533-694l-13-106h-79l-14 106q-31 8-57.5 23.5T321-633l-99-41-39 68 86 64q-5 15-7 30t-2 32q0 16 2 31t7 30l-86 65 39 68 99-42q22 23 48.5 38.5T427-266l13 106Zm42-180q58 0 99-41t41-99q0-58-41-99t-99-41q-59 0-99.5 41T342-480q0 58 40.5 99t99.5 41Zm-2-140Z"></path></svg>`);
}
const _sfc_setup$2 = _sfc_main$2.setup;
_sfc_main$2.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Icons/Cog.vue");
  return _sfc_setup$2 ? _sfc_setup$2(props, ctx) : void 0;
};
const CogIcon = /* @__PURE__ */ _export_sfc(_sfc_main$2, [["ssrRender", _sfc_ssrRender$1]]);
const _sfc_main$1 = {};
function _sfc_ssrRender(_ctx, _push, _parent, _attrs) {
  _push(`<svg${ssrRenderAttrs(mergeProps({
    xmlns: "http://www.w3.org/2000/svg",
    viewBox: "0 -960 960 960"
  }, _attrs))}><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"></path></svg>`);
}
const _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Icons/Logout.vue");
  return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
const LogoutIcon = /* @__PURE__ */ _export_sfc(_sfc_main$1, [["ssrRender", _sfc_ssrRender]]);
const _sfc_main = {
  __name: "AuthenticatedLayout",
  __ssrInlineRender: true,
  setup(__props) {
    const showingNavigationDropdown = ref(false);
    return (_ctx, _push, _parent, _attrs) => {
      const _component_FolderIcon = resolveComponent("FolderIcon");
      _push(`<div${ssrRenderAttrs(_attrs)}><div class="flex flex-col gap-10 min-h-screen bg-minizo-dark"><div><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><div class="flex h-16 justify-between"><div class="flex"><div class="flex shrink-0 items-center">`);
      _push(ssrRenderComponent(unref(Link), {
        href: _ctx.route("dashboard"),
        class: "flex gap-2 items-center"
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(ssrRenderComponent(ApplicationLogo, { class: "block h-10 w-auto" }, null, _parent2, _scopeId));
            _push2(`<span class="text-white"${_scopeId}>Minizo</span>`);
          } else {
            return [
              createVNode(ApplicationLogo, { class: "block h-10 w-auto" }),
              createVNode("span", { class: "text-white" }, "Minizo")
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</div></div><div class="hidden sm:ms-6 sm:flex sm:items-center"><ul class="flex gap-6"><li><a href="https://github.com/mattiasghodsian/Minizo" class="flex items-center gap-4 text-lg group text-gray-400 p hover:text-white" target="_blank">`);
      _push(ssrRenderComponent(GithubIcon, { class: "w-7 h-7 fill-gray-400 group-hover:fill-white" }, null, _parent));
      _push(` Github </a></li><li><a href="https://hub.docker.com/r/rakma/minizo" class="flex items-center gap-4 text-lg group text-gray-400 p hover:text-white" target="_blank">`);
      _push(ssrRenderComponent(DockerIcon, { class: "w-7 h-7 fill-gray-400 group-hover:fill-white" }, null, _parent));
      _push(` Docker </a></li></ul></div><div class="-me-2 flex items-center sm:hidden"><button class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-500 dark:hover:bg-gray-900 dark:hover:text-gray-400 dark:focus:bg-gray-900 dark:focus:text-gray-400"><svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24"><path class="${ssrRenderClass({
        hidden: showingNavigationDropdown.value,
        "inline-flex": !showingNavigationDropdown.value
      })}" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path><path class="${ssrRenderClass({
        hidden: !showingNavigationDropdown.value,
        "inline-flex": showingNavigationDropdown.value
      })}" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div></div></div></div><div class="flex flex-row gap-12 w-full mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><nav class="min-w-[200px]"><ul class="flex flex-col gap-3"><li>`);
      _push(ssrRenderComponent(_sfc_main$4, {
        href: _ctx.route("dashboard"),
        active: _ctx.route().current("dashboard")
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(ssrRenderComponent(DownloadIcon, { class: "h-6 w-6 fill-gray-400" }, null, _parent2, _scopeId));
            _push2(` Download `);
          } else {
            return [
              createVNode(DownloadIcon, { class: "h-6 w-6 fill-gray-400" }),
              createTextVNode(" Download ")
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</li><li class="border-b border-gray-800 my-2 mx-4"></li><!--[-->`);
      ssrRenderList(_ctx.$page.props.library.directories, (directory) => {
        _push(`<li>`);
        _push(ssrRenderComponent(_sfc_main$4, {
          href: _ctx.route("library", { directory: directory.name })
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(ssrRenderComponent(_component_FolderIcon, { class: "h-6 w-6 fill-gray-400" }, null, _parent2, _scopeId));
              _push2(` ${ssrInterpolate(directory.name)}`);
            } else {
              return [
                createVNode(_component_FolderIcon, { class: "h-6 w-6 fill-gray-400" }),
                createTextVNode(" " + toDisplayString(directory.name), 1)
              ];
            }
          }),
          _: 2
        }, _parent));
        _push(`</li>`);
      });
      _push(`<!--]--><li class="border-b border-gray-800 my-2 mx-4"></li><li>`);
      _push(ssrRenderComponent(_sfc_main$4, {
        href: _ctx.route("profile.edit")
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(ssrRenderComponent(CogIcon, { class: "w-6 h-6 fill-gray-400" }, null, _parent2, _scopeId));
            _push2(` ${ssrInterpolate(_ctx.$page.props.auth.user.name)}`);
          } else {
            return [
              createVNode(CogIcon, { class: "w-6 h-6 fill-gray-400" }),
              createTextVNode(" " + toDisplayString(_ctx.$page.props.auth.user.name), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</li><li>`);
      _push(ssrRenderComponent(_sfc_main$4, { href: "#" }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(ssrRenderComponent(LogoutIcon, { class: "w-6 h-6 fill-gray-400" }, null, _parent2, _scopeId));
            _push2(` Logout `);
          } else {
            return [
              createVNode(LogoutIcon, { class: "w-6 h-6 fill-gray-400" }),
              createTextVNode(" Logout ")
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</li></ul></nav><main class="flex flex-col gap-10 w-full max-w-[968px] pb-4">`);
      ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
      _push(`</main></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Layouts/AuthenticatedLayout.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
