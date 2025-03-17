import { unref, withCtx, createVNode, useSSRContext } from "vue";
import { ssrRenderComponent } from "vue/server-renderer";
import { _ as _sfc_main$1 } from "./AuthenticatedLayout-CiAYwLCu.js";
import "./DeleteUserForm-E3_Z9tHs.js";
import _sfc_main$3 from "./UpdatePasswordForm-BaC6Cd1O.js";
import _sfc_main$2 from "./UpdateProfileInformationForm-DbVdjtoa.js";
import { Head } from "@inertiajs/vue3";
import "./Docker-C_fQ0Mgd.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./InputError-D7Pvlg8p.js";
import "./TextInput-DqLOxehv.js";
import "./PrimaryButton-Cygj-hvK.js";
const _sfc_main = {
  __name: "Edit",
  __ssrInlineRender: true,
  props: {
    mustVerifyEmail: {
      type: Boolean
    },
    status: {
      type: String
    }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), { title: "Profile" }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, null, {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"${_scopeId}> Profile </h2>`);
          } else {
            return [
              createVNode("h2", { class: "text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200" }, " Profile ")
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="grid grid-cols-2 gap-6"${_scopeId}><div class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md"${_scopeId}><h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md"${_scopeId}>Profile</h1>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              "must-verify-email": __props.mustVerifyEmail,
              status: __props.status,
              class: "max-w-xl"
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md"${_scopeId}><h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md"${_scopeId}>Password</h1>`);
            _push2(ssrRenderComponent(_sfc_main$3, { class: "max-w-xl" }, null, _parent2, _scopeId));
            _push2(`</div></div>`);
          } else {
            return [
              createVNode("div", { class: "grid grid-cols-2 gap-6" }, [
                createVNode("div", { class: "bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md" }, [
                  createVNode("h1", { class: "text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md" }, "Profile"),
                  createVNode(_sfc_main$2, {
                    "must-verify-email": __props.mustVerifyEmail,
                    status: __props.status,
                    class: "max-w-xl"
                  }, null, 8, ["must-verify-email", "status"])
                ]),
                createVNode("div", { class: "bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md" }, [
                  createVNode("h1", { class: "text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md" }, "Password"),
                  createVNode(_sfc_main$3, { class: "max-w-xl" })
                ])
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Profile/Edit.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
