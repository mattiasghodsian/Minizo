// Core Source: https://gist.github.com/mohitmamoria/91da6f30d9610b211248225c9e52bebe
import { useForm } from '@inertiajs/vue3';
import cloneDeep from 'lodash.clonedeep';

export function hasFiles(data) {
    return (
        data instanceof File ||
        data instanceof Blob ||
        (data instanceof FileList && data.length > 0) ||
        (data instanceof FormData && Array.from(data.values()).some((value) => hasFiles(value))) ||
        (typeof data === 'object' && data !== null && Object.values(data).some((value) => hasFiles(value)))
    );
}

export function useAPIForm(rememberKeyOrData, maybeData = null) {
    const form = useForm(rememberKeyOrData, maybeData);
    let transform = (data) => data;
    let recentlySuccessfulTimeoutId = null;

    const overriders = {
        transform: (receiver) => (callback) => {
            transform = callback;
            return receiver;
        },
        submit: (receiver) => (method, url, options = {}) => {
            // BEFORE
            form.wasSuccessful = false;
            form.recentlySuccessful = false;
            form.clearErrors();
            clearTimeout(recentlySuccessfulTimeoutId);
            if (options.onBefore) {
                options.onBefore();
            }

            // START
            form.processing = true;
            if (options.onStart) {
                options.onStart();
            }

            // MAKING THE CALL
            const data = transform(form.data());

            // Configure request based on method
            const config = {
                method: method.toLowerCase(),
                url: url,
                headers: {
                    ...options.headers,
                    'Content-Type': hasFiles(data) ? 'multipart/form-data' : 'application/json',
                },
                onUploadProgress: (event) => {
                    form.progress = event;
                    if (options.onProgress) {
                        options.onProgress(event);
                    }
                }
            };

            // Handle GET requests differently
            if (method.toLowerCase() === 'get') {
                config.params = data;
            } else {
                config.data = data;
            }

            // Make the request
            axios(config)
                .then((response) => {
                    form.processing = false;
                    form.progress = null;
                    form.clearErrors();
                    form.wasSuccessful = true;
                    form.recentlySuccessful = true;
                    recentlySuccessfulTimeoutId = setTimeout(() => (form.recentlySuccessful = false), 2000);

                    if (options.onSuccess) {
                        options.onSuccess(response.data);
                    }

                    form.defaults(cloneDeep(form.data()));
                    form.isDirty = false;
                })
                .catch((error) => {
                    form.processing = false;
                    form.progress = null;

                    // Set validation errors
                    form.clearErrors();
                    if (error.response?.status === 422) {
                        Object.keys(error.response.data.errors).forEach((key) => {
                            form.setError(key, error.response.data.errors[key][0]);
                        });
                    }

                    if (options.onError) {
                        options.onError(error);
                    }
                })
                .finally(() => {
                    form.processing = false;
                    form.progress = null;

                    if (options.onFinish) {
                        options.onFinish();
                    }
                });
        },
    };

    return new Proxy(form, {
        get: (target, prop, receiver) => {
            return Object.keys(overriders).indexOf(prop) < 0 
                ? target[prop] 
                : overriders[prop](receiver);
        },
    });
}