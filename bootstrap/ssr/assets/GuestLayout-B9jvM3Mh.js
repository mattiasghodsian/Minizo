import { mergeProps, unref, withCtx, createVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderComponent, ssrRenderSlot } from "vue/server-renderer";
import { A as ApplicationLogo, G as GithubIcon, D as DockerIcon } from "./Docker-C_fQ0Mgd.js";
import { Link } from "@inertiajs/vue3";
const _sfc_main = {
  __name: "GuestLayout",
  __ssrInlineRender: true,
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "flex min-h-screen flex-col items-center pt-6 sm:justify-center sm:pt-0 bg-minizo-dark" }, _attrs))}><div>`);
      _push(ssrRenderComponent(unref(Link), { href: "/" }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(ssrRenderComponent(ApplicationLogo, { class: "h-28" }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode(ApplicationLogo, { class: "h-28" })
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</div><div class="mt-6 w-full overflow-hidde px-6 py-4 sm:max-w-md sm:rounded-lg">`);
      ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
      _push(`</div><div class="mt-4"><ul class="flex gap-4"><li><a href="https://github.com/mattiasghodsian/Minizo" target="_blank">`);
      _push(ssrRenderComponent(GithubIcon, { class: "w-7 h-7 fill-gray-400 hover:fill-white transition-all delay-150" }, null, _parent));
      _push(`</a></li><li><a href="https://hub.docker.com/r/rakma/minizo" target="_blank">`);
      _push(ssrRenderComponent(DockerIcon, { class: "w-7 h-7 fill-gray-400 hover:fill-white transition-all delay-150" }, null, _parent));
      _push(`</a></li></ul></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Layouts/GuestLayout.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
