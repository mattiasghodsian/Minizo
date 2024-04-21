/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [],
  purge: ['./index.html', './src-frontend/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        minizo: {
          'green': '#0F8244',
          'red': '#EF3340',
          'base': '#1E1E1E',
        },
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}

